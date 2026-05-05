<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use Illuminate\Support\Facades\Artisan;
use Oxhq\Preview\Capture\CaptureRepository;
use Oxhq\Preview\Capture\PreviewRequest;
use Oxhq\Preview\Core\ProviderRegistry;
use Oxhq\Preview\Providers\PreviewProvider;
use Oxhq\Preview\Providers\ProviderCapability;
use Oxhq\Preview\Providers\VerificationResult;
use Oxhq\Preview\Tests\TestCase;

final class CaptureVerifyCommandTest extends TestCase
{
    public function test_it_reruns_provider_verification_against_raw_capture_body_headers_and_metadata_context(): void
    {
        $body = '{"secret":"body-secret","ok":true}';
        $signature = hash_hmac('sha512', $body, 'test-secret');

        $this->app['config']->set('preview.hmac.algorithm', 'sha512');

        $this->artisan('preview:capture', [
            'provider' => 'hmac',
            '--signature-header' => 'X-Custom-Signature',
            '--path' => '/webhooks/signed',
            '--body' => $body,
            '--header' => [
                'X-Custom-Signature: '.$signature,
                'X-Preview-Event: raw.event',
                'Authorization: Bearer verify-secret',
            ],
        ])->assertExitCode(0);

        $record = app(CaptureRepository::class)->all()[0];
        $metadataPath = dirname($record->rawBodyPath).DIRECTORY_SEPARATOR.'metadata.json';
        $metadata = json_decode((string) file_get_contents($metadataPath), true, flags: JSON_THROW_ON_ERROR);
        $metadata['event_type'] = 'metadata.event';
        $metadata['headers']['X-Custom-Signature'] = 'invalid-metadata-signature';
        $metadata['headers']['X-Preview-Event'] = 'metadata.event';
        file_put_contents($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $exitCode = Artisan::call('preview:capture:verify', [
            'capture' => $record->id,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($record->id, $payload['capture_id']);
        $this->assertSame('hmac', $payload['provider']);
        $this->assertTrue($payload['verified']);
        $this->assertNull($payload['verification_message']);
        $this->assertSame('raw.event', $payload['event_type']);
        $this->assertArrayNotHasKey('raw_body', $payload);
        $this->assertArrayNotHasKey('headers', $payload);
        $this->assertStringNotContainsString('body-secret', Artisan::output());
        $this->assertStringNotContainsString('verify-secret', Artisan::output());
        $this->assertStringNotContainsString($signature, Artisan::output());
    }

    public function test_it_reports_not_verified_without_printing_raw_content_or_secret_values(): void
    {
        $body = '{"secret":"body-secret","ok":false}';

        $this->artisan('preview:capture', [
            'provider' => 'hmac',
            '--signature-header' => 'X-Custom-Signature',
            '--path' => '/webhooks/signed',
            '--body' => $body,
            '--header' => [
                'X-Custom-Signature: invalid-signature',
                'Authorization: Bearer verify-secret',
            ],
        ])->assertExitCode(0);

        $record = app(CaptureRepository::class)->all()[0];

        $this->artisan('preview:capture:verify', [
            'capture' => $record->id,
        ])
            ->expectsOutputToContain("Capture [{$record->id}] verification: not verified")
            ->expectsOutput('Verification message: Invalid HMAC signature.')
            ->assertExitCode(1);

        $this->assertStringNotContainsString('body-secret', Artisan::output());
        $this->assertStringNotContainsString('verify-secret', Artisan::output());
        $this->assertStringNotContainsString('invalid-signature', Artisan::output());
    }

    public function test_it_redacts_provider_messages_that_include_raw_body_or_header_values(): void
    {
        $registry = new ProviderRegistry();
        $registry->register(new LeakyVerificationProvider());
        $this->app->instance(ProviderRegistry::class, $registry);

        $record = app(CaptureRepository::class)->store(
            PreviewRequest::make(
                provider: 'leaky',
                method: 'POST',
                path: '/webhooks/leaky',
                headers: ['Authorization' => 'Bearer leaked-header'],
                rawBody: '{"secret":"leaked-body"}',
            ),
            new LeakyVerificationProvider(),
        );

        $exitCode = Artisan::call('preview:capture:verify', [
            'capture' => $record->id,
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('[redacted]', Artisan::output());
        $this->assertStringNotContainsString('leaked-body', Artisan::output());
        $this->assertStringNotContainsString('leaked-header', Artisan::output());
    }
}

final class LeakyVerificationProvider implements PreviewProvider
{
    public function name(): string
    {
        return 'leaky';
    }

    public function capabilities(): array
    {
        return [ProviderCapability::VerifiesSignature];
    }

    public function verify(PreviewRequest $request): VerificationResult
    {
        return VerificationResult::failed(
            'Rejected body '.$request->rawBody.' with authorization '.$request->headers['Authorization'],
        );
    }

    public function eventType(PreviewRequest $request): ?string
    {
        return null;
    }

    public function fixtureName(PreviewRequest $request): string
    {
        return 'leaky';
    }

    public function fixtureContext(PreviewRequest $request): array
    {
        return [];
    }

    public function canSign(): bool
    {
        return false;
    }

    public function sign(string $rawBody, array $headers = []): array
    {
        return $headers;
    }
}

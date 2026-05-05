<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Oxhq\Preview\Capture\CaptureRecord;
use Oxhq\Preview\Capture\CaptureRepository;
use Oxhq\Preview\Capture\PreviewRequest;
use Oxhq\Preview\Commands\CaptureCompareCommand;
use Oxhq\Preview\Providers\GenericProvider;
use Oxhq\Preview\Tests\TestCase;

final class CaptureCompareCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(Kernel::class)->registerCommand($this->app->make(CaptureCompareCommand::class));
    }

    public function test_json_output_reports_same_captures_without_raw_payloads_or_header_values(): void
    {
        $repository = app(CaptureRepository::class);
        $left = $this->storeGenericCapture($repository, '{"secret":"same-body"}', [
            'X-Preview-Event' => 'order.created',
            'Authorization' => 'Bearer compare-secret',
        ]);
        $right = $this->storeGenericCapture($repository, '{"secret":"same-body"}', [
            'X-Preview-Event' => 'order.created',
            'Authorization' => 'Bearer compare-secret',
        ]);

        $exitCode = Artisan::call('preview:capture:compare', [
            'left' => $left->id,
            'right' => $right->id,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($payload['same']);
        $this->assertSame([], $payload['differences']);
        $this->assertTrue($payload['fields']['provider']['same']);
        $this->assertTrue($payload['fields']['event_type']['same']);
        $this->assertTrue($payload['fields']['method']['same']);
        $this->assertTrue($payload['fields']['path']['same']);
        $this->assertTrue($payload['fields']['query']['same']);
        $this->assertTrue($payload['fields']['header_keys']['same']);
        $this->assertTrue($payload['fields']['verified']['same']);
        $this->assertTrue($payload['fields']['raw_body_sha256']['same']);
        $this->assertTrue($payload['fields']['raw_headers_sha256']['same']);
        $this->assertSame(['Authorization', 'X-Preview-Event'], $payload['fields']['header_keys']['left']);
        $this->assertSame(hash_file('sha256', $left->rawBodyPath), $payload['fields']['raw_body_sha256']['left']);
        $this->assertSame(hash_file('sha256', (string) $left->rawHeadersPath), $payload['fields']['raw_headers_sha256']['left']);
        $this->assertArrayNotHasKey('headers', $payload['fields']);
        $this->assertStringNotContainsString('same-body', Artisan::output());
        $this->assertStringNotContainsString('compare-secret', Artisan::output());
        $this->assertStringNotContainsString('Bearer compare-secret', Artisan::output());
    }

    public function test_json_output_reports_differences_without_raw_payloads_or_header_values(): void
    {
        $repository = app(CaptureRepository::class);
        $left = $this->storeGenericCapture($repository, '{"secret":"left-body"}', [
            'X-Preview-Event' => 'order.created',
            'Authorization' => 'Bearer left-secret',
        ], path: '/webhooks/orders', query: ['status' => 'paid']);
        $right = $this->storeGenericCapture($repository, '{"secret":"right-body"}', [
            'X-Preview-Event' => 'refund.created',
            'X-New-Header' => 'visible-but-still-not-output',
            'Authorization' => 'Bearer right-secret',
        ], path: '/webhooks/refunds', query: ['status' => 'refunded']);

        $exitCode = Artisan::call('preview:capture:compare', [
            'left' => $left->id,
            'right' => $right->id,
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertFalse($payload['same']);
        $this->assertSame([
            'event_type',
            'path',
            'query',
            'header_keys',
            'raw_body_sha256',
            'raw_headers_sha256',
        ], $payload['differences']);
        $this->assertSame('/webhooks/orders', $payload['fields']['path']['left']);
        $this->assertSame('/webhooks/refunds', $payload['fields']['path']['right']);
        $this->assertSame(['Authorization', 'X-Preview-Event'], $payload['fields']['header_keys']['left']);
        $this->assertSame(['Authorization', 'X-New-Header', 'X-Preview-Event'], $payload['fields']['header_keys']['right']);
        $this->assertNotSame($payload['fields']['raw_body_sha256']['left'], $payload['fields']['raw_body_sha256']['right']);
        $this->assertNotSame($payload['fields']['raw_headers_sha256']['left'], $payload['fields']['raw_headers_sha256']['right']);
        $this->assertStringNotContainsString('left-body', Artisan::output());
        $this->assertStringNotContainsString('right-body', Artisan::output());
        $this->assertStringNotContainsString('left-secret', Artisan::output());
        $this->assertStringNotContainsString('right-secret', Artisan::output());
        $this->assertStringNotContainsString('visible-but-still-not-output', Artisan::output());
    }

    public function test_human_output_summarizes_same_and_differences_without_secret_values(): void
    {
        $repository = app(CaptureRepository::class);
        $left = $this->storeGenericCapture($repository, 'left-human-secret', [
            'Authorization' => 'Bearer human-left-secret',
        ]);
        $right = $this->storeGenericCapture($repository, 'right-human-secret', [
            'Authorization' => 'Bearer human-right-secret',
            'X-New-Header' => 'human-header-value',
        ]);

        $this->artisan('preview:capture:compare', [
            'left' => $left->id,
            'right' => $right->id,
        ])
            ->expectsOutputToContain("Captures [{$left->id}] and [{$right->id}] differ.")
            ->expectsOutput('Same: provider, event_type, method, path, query, verified')
            ->expectsOutput('Differences: header_keys, raw_body_sha256, raw_headers_sha256')
            ->assertExitCode(1);

        $output = Artisan::output();

        $this->assertStringNotContainsString('left-human-secret', $output);
        $this->assertStringNotContainsString('right-human-secret', $output);
        $this->assertStringNotContainsString('human-left-secret', $output);
        $this->assertStringNotContainsString('human-right-secret', $output);
        $this->assertStringNotContainsString('human-header-value', $output);
    }

    private function storeGenericCapture(
        CaptureRepository $repository,
        string $body,
        array $headers,
        string $path = '/webhooks/orders',
        array $query = [],
    ): CaptureRecord {
        return $repository->store(
            new PreviewRequest(
                provider: 'generic',
                method: 'POST',
                path: $path,
                query: $query,
                headers: $headers,
                rawBody: $body,
                capturedAt: new DateTimeImmutable('2026-01-01T10:00:00+00:00'),
            ),
            new GenericProvider(),
        );
    }
}

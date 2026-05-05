<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use DateTimeImmutable;
use Illuminate\Support\Facades\Artisan;
use Oxhq\Preview\Capture\CaptureRepository;
use Oxhq\Preview\Capture\PreviewRequest;
use Oxhq\Preview\Providers\GenericHmacProvider;
use Oxhq\Preview\Providers\GenericProvider;
use Oxhq\Preview\Tests\TestCase;

final class CaptureStatsCommandTest extends TestCase
{
    public function test_stats_json_summarizes_capture_inventory_without_payloads_or_secrets(): void
    {
        $repository = app(CaptureRepository::class);

        $this->storeGenericCapture($repository, '/orders', '2026-01-01T10:00:00+00:00', [
            'X-Preview-Event' => 'order.created',
            'Authorization' => 'Bearer stats-secret',
        ], '{"secret":"payload-one"}');

        $this->storeHmacCapture($repository, '/payments', '2026-01-02T11:30:00+00:00', [
            'X-Preview-Event' => 'payment.succeeded',
        ], '{"secret":"payload-two"}');

        $this->storeGenericCapture($repository, '/unknown', '2025-12-31T09:00:00+00:00', [], '{"secret":"payload-three"}');

        $exitCode = Artisan::call('preview:capture:stats', [
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(3, $payload['total']);
        $this->assertSame(1, $payload['verified']);
        $this->assertSame(2, $payload['unverified']);
        $this->assertSame(['generic' => 2, 'hmac' => 1], $payload['by_provider']);
        $this->assertSame([
            'order.created' => 1,
            'payment.succeeded' => 1,
            'unknown' => 1,
        ], $payload['by_event_type']);
        $this->assertSame('2025-12-31T09:00:00+00:00', $payload['oldest_captured_at']);
        $this->assertSame('2026-01-02T11:30:00+00:00', $payload['newest_captured_at']);
        $this->assertStringNotContainsString('stats-secret', Artisan::output());
        $this->assertStringNotContainsString('payload-one', Artisan::output());
        $this->assertStringNotContainsString('payload-two', Artisan::output());
        $this->assertStringNotContainsString('payload-three', Artisan::output());
    }

    public function test_stats_outputs_a_human_readable_summary(): void
    {
        $repository = app(CaptureRepository::class);

        $this->storeGenericCapture($repository, '/orders', '2026-01-01T10:00:00+00:00', [
            'X-Preview-Event' => 'order.created',
        ]);
        $this->storeHmacCapture($repository, '/payments', '2026-01-02T11:30:00+00:00');

        $this->artisan('preview:capture:stats')
            ->expectsOutput('Total captures: 2')
            ->expectsOutput('Verified: 1')
            ->expectsOutput('Unverified: 1')
            ->expectsOutput('Oldest captured_at: 2026-01-01T10:00:00+00:00')
            ->expectsOutput('Newest captured_at: 2026-01-02T11:30:00+00:00')
            ->expectsOutputToContain('generic')
            ->expectsOutputToContain('hmac')
            ->expectsOutputToContain('order.created')
            ->expectsOutputToContain('unknown')
            ->assertExitCode(0);
    }

    private function storeGenericCapture(
        CaptureRepository $repository,
        string $path,
        string $capturedAt,
        array $headers = [],
        string $body = '{}',
    ): void {
        $repository->store(
            new PreviewRequest('generic', 'POST', $path, [], $headers, $body, new DateTimeImmutable($capturedAt)),
            new GenericProvider(),
        );
    }

    private function storeHmacCapture(
        CaptureRepository $repository,
        string $path,
        string $capturedAt,
        array $headers = [],
        string $body = '{}',
    ): void {
        $provider = new GenericHmacProvider('X-Signature', 'test-secret');
        $headers = $provider->sign($body, $headers);

        $repository->store(
            new PreviewRequest('hmac', 'POST', $path, [], $headers, $body, new DateTimeImmutable($capturedAt)),
            $provider,
        );
    }
}

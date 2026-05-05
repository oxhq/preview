<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Oxhq\Preview\Capture\CaptureRecord;
use Oxhq\Preview\Capture\CaptureRepository;
use Oxhq\Preview\Capture\PreviewRequest;
use Oxhq\Preview\Commands\CaptureTimelineCommand;
use Oxhq\Preview\Providers\GenericHmacProvider;
use Oxhq\Preview\Providers\GenericProvider;
use Oxhq\Preview\Tests\TestCase;

final class CaptureTimelineCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(Kernel::class)->registerCommand(app(CaptureTimelineCommand::class));
    }

    public function test_timeline_json_summarizes_captures_in_ascending_capture_order_without_raw_payloads(): void
    {
        $repository = app(CaptureRepository::class);

        $newest = $this->storeGenericCapture($repository, '/orders/newest', '2026-01-03T10:00:00+00:00', [
            'X-Preview-Event' => 'order.created',
            'Authorization' => 'Bearer timeline-secret',
        ], '{"secret":"newest-payload"}');

        $oldest = $this->storeHmacCapture($repository, '/payments/oldest', '2026-01-01T09:00:00+00:00', [
            'X-Preview-Event' => 'payment.succeeded',
        ], '{"secret":"oldest-payload"}');

        $middle = $this->storeGenericCapture($repository, '/orders/middle', '2026-01-02T11:30:00+00:00', [
            'X-Preview-Event' => 'order.updated',
        ], '{"secret":"middle-payload"}');

        $exitCode = Artisan::call('preview:capture:timeline', [
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(3, $payload['count']);
        $this->assertSame('2026-01-01T09:00:00+00:00', $payload['first']);
        $this->assertSame('2026-01-03T10:00:00+00:00', $payload['last']);
        $this->assertSame([$oldest->id, $middle->id, $newest->id], array_column($payload['captures'], 'id'));
        $this->assertSame([
            [
                'id' => $oldest->id,
                'provider' => 'hmac',
                'event' => 'payment.succeeded',
                'path' => '/payments/oldest',
                'verified' => true,
                'body_bytes' => 27,
            ],
            [
                'id' => $middle->id,
                'provider' => 'generic',
                'event' => 'order.updated',
                'path' => '/orders/middle',
                'verified' => false,
                'body_bytes' => 27,
            ],
            [
                'id' => $newest->id,
                'provider' => 'generic',
                'event' => 'order.created',
                'path' => '/orders/newest',
                'verified' => false,
                'body_bytes' => 27,
            ],
        ], $payload['captures']);
        $this->assertStringNotContainsString('timeline-secret', Artisan::output());
        $this->assertStringNotContainsString('newest-payload', Artisan::output());
        $this->assertStringNotContainsString('middle-payload', Artisan::output());
        $this->assertStringNotContainsString('oldest-payload', Artisan::output());
        $this->assertArrayNotHasKey('headers', $payload['captures'][0]);
        $this->assertArrayNotHasKey('raw_headers', $payload['captures'][0]);
        $this->assertArrayNotHasKey('raw_body', $payload['captures'][0]);
    }

    public function test_timeline_can_filter_by_provider_and_render_human_summary(): void
    {
        $repository = app(CaptureRepository::class);

        $this->storeGenericCapture($repository, '/orders', '2026-01-02T11:30:00+00:00', [
            'X-Preview-Event' => 'order.created',
        ], '{"hidden":"generic"}');
        $hmac = $this->storeHmacCapture($repository, '/payments', '2026-01-01T09:00:00+00:00', [
            'X-Preview-Event' => 'payment.succeeded',
            'Authorization' => 'Bearer hmac-secret',
        ], '{"hidden":"hmac"}');

        $exitCode = Artisan::call('preview:capture:timeline', [
            '--provider' => 'hmac',
        ]);

        $this->assertSame(0, $exitCode);

        $output = Artisan::output();

        $this->assertStringContainsString('Capture timeline', $output);
        $this->assertStringContainsString('Count: 1', $output);
        $this->assertStringContainsString('First captured_at: 2026-01-01T09:00:00+00:00', $output);
        $this->assertStringContainsString('Last captured_at: 2026-01-01T09:00:00+00:00', $output);
        $this->assertStringContainsString($hmac->id, $output);
        $this->assertStringContainsString('hmac', $output);
        $this->assertStringContainsString('payment.succeeded', $output);
        $this->assertStringContainsString('/payments', $output);
        $this->assertStringNotContainsString('generic', $output);
        $this->assertStringNotContainsString('hmac-secret', $output);
        $this->assertStringNotContainsString('{"hidden":"hmac"}', $output);
    }

    private function storeGenericCapture(
        CaptureRepository $repository,
        string $path,
        string $capturedAt,
        array $headers = [],
        string $body = '{}',
    ): CaptureRecord {
        return $repository->store(
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
    ): CaptureRecord {
        $provider = new GenericHmacProvider('X-Signature', 'test-secret');
        $headers = $provider->sign($body, $headers);

        return $repository->store(
            new PreviewRequest('hmac', 'POST', $path, [], $headers, $body, new DateTimeImmutable($capturedAt)),
            $provider,
        );
    }
}

<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Route;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Oxhq\Preview\Route\RoutePreviewService;
use Oxhq\Preview\Tests\TestCase;

final class RoutePreviewFakeIsolationTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        RoutePreviewIsolationEventListener::$calls = 0;
        RoutePreviewIsolationJob::$handled = 0;

        parent::tearDown();
    }

    public function test_fake_events_prevents_listener_side_effects_during_proxied_execution(): void
    {
        if (! class_exists(Event::class) || ! method_exists(Event::class, 'fake')) {
            $this->markTestSkipped('Laravel Event fake is unavailable in this package install.');
        }

        Carbon::setTestNow(Carbon::parse('2026-05-04 12:00:00', 'UTC'));
        RoutePreviewIsolationEventListener::$calls = 0;

        Event::listen(RoutePreviewIsolationEvent::class, RoutePreviewIsolationEventListener::class);

        Route::get('/preview-fakes/events', function (): string {
            event(new RoutePreviewIsolationEvent());

            return 'event dispatched';
        })->name('preview.fakes.events');

        $preview = app(RoutePreviewService::class)->preview(
            routeName: 'preview.fakes.events',
            ttl: '30m',
            fakes: ['events'],
        );

        $this->get($preview->url)
            ->assertOk()
            ->assertSee('event dispatched');

        Event::assertDispatched(RoutePreviewIsolationEvent::class);
        $this->assertSame(0, RoutePreviewIsolationEventListener::$calls);
    }

    public function test_fake_http_prevents_real_outbound_http_and_records_fake_request_during_proxied_execution(): void
    {
        if (! class_exists(Http::class) || ! method_exists(Http::class, 'fake')) {
            $this->markTestSkipped('Laravel HTTP fake is unavailable in this package install.');
        }

        Carbon::setTestNow(Carbon::parse('2026-05-04 12:00:00', 'UTC'));

        Route::get('/preview-fakes/http', function (): string {
            return Http::get('https://route-preview-isolation.example.test/ping')->body();
        })->name('preview.fakes.http');

        $preview = app(RoutePreviewService::class)->preview(
            routeName: 'preview.fakes.http',
            ttl: '30m',
            fakes: ['http'],
        );

        $this->get($preview->url)
            ->assertOk();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://route-preview-isolation.example.test/ping');
    }

    public function test_fake_queue_prevents_queued_job_execution_and_captures_dispatch_during_proxied_execution(): void
    {
        if (
            ! interface_exists(ShouldQueue::class)
            || ! class_exists(Queue::class)
            || ! method_exists(Queue::class, 'fake')
        ) {
            $this->markTestSkipped('Laravel Queue fake is unavailable in this package install.');
        }

        Carbon::setTestNow(Carbon::parse('2026-05-04 12:00:00', 'UTC'));
        RoutePreviewIsolationJob::$handled = 0;

        Route::get('/preview-fakes/queue', function (): string {
            dispatch(new RoutePreviewIsolationJob());

            return 'job dispatched';
        })->name('preview.fakes.queue');

        $preview = app(RoutePreviewService::class)->preview(
            routeName: 'preview.fakes.queue',
            ttl: '30m',
            fakes: ['queue'],
        );

        $this->get($preview->url)
            ->assertOk()
            ->assertSee('job dispatched');

        Queue::assertPushed(RoutePreviewIsolationJob::class);
        $this->assertSame(0, RoutePreviewIsolationJob::$handled);
    }
}

final class RoutePreviewIsolationEvent
{
}

final class RoutePreviewIsolationEventListener
{
    public static int $calls = 0;

    public function handle(RoutePreviewIsolationEvent $event): void
    {
        self::$calls++;
    }
}

final class RoutePreviewIsolationJob implements ShouldQueue
{
    public static int $handled = 0;

    public function handle(): void
    {
        self::$handled++;
    }
}

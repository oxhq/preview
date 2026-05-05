<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Route;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Oxhq\Preview\Route\RoutePreviewService;
use Oxhq\Preview\Tests\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

final class RoutePreviewFakeIsolationTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        RoutePreviewIsolationEventListener::$calls = 0;
        RoutePreviewIsolationJob::$handled = 0;
        RoutePreviewIsolationMailTransport::$sent = 0;

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

    public function test_fake_mail_prevents_actual_mail_send_and_records_mailable_during_proxied_execution(): void
    {
        if (
            ! class_exists(Mail::class)
            || ! method_exists(Mail::class, 'fake')
            || ! class_exists(Mailable::class)
            || ! interface_exists(TransportInterface::class)
        ) {
            $this->markTestSkipped('Laravel Mail fake is unavailable in this package install.');
        }

        Carbon::setTestNow(Carbon::parse('2026-05-04 12:00:00', 'UTC'));
        RoutePreviewIsolationMailTransport::$sent = 0;

        Mail::extend('preview_isolation', fn (): RoutePreviewIsolationMailTransport => new RoutePreviewIsolationMailTransport());
        config()->set('mail.default', 'preview_isolation');
        config()->set('mail.mailers.preview_isolation', ['transport' => 'preview_isolation']);
        Mail::purge('preview_isolation');

        Route::get('/preview-fakes/mail', function (): string {
            Mail::to('reviewer@example.test')->send(new RoutePreviewIsolationMailable());

            return 'mail sent';
        })->name('preview.fakes.mail');

        $preview = app(RoutePreviewService::class)->preview(
            routeName: 'preview.fakes.mail',
            ttl: '30m',
            fakes: ['mail'],
        );

        $this->assertSame(['mail'], $preview->fakes);

        $this->get($preview->url)
            ->assertOk()
            ->assertSee('mail sent');

        Mail::assertSent(RoutePreviewIsolationMailable::class, 'reviewer@example.test');
        $this->assertSame(0, RoutePreviewIsolationMailTransport::$sent);
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

final class RoutePreviewIsolationMailable extends Mailable
{
    public function build(): self
    {
        return $this
            ->subject('Preview isolation mail')
            ->html('Preview isolation mail body');
    }
}

final class RoutePreviewIsolationMailTransport implements TransportInterface
{
    public static int $sent = 0;

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        self::$sent++;

        return new SentMessage($message, $envelope ?? Envelope::create($message));
    }

    public function __toString(): string
    {
        return 'preview-isolation';
    }
}

<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use Oxhq\Preview\Capture\CaptureRepository;
use Oxhq\Preview\Capture\HttpReplayDispatcher;
use Oxhq\Preview\Capture\ReplayResult;
use Oxhq\Preview\Core\Transport\TransportRegistry;
use Oxhq\Preview\Core\Transport\TunnelHandle;
use Oxhq\Preview\Core\Transport\TunnelTransport;
use Oxhq\Preview\Tests\TestCase;

final class CaptureCommandsTest extends TestCase
{
    public function test_capture_command_rejects_unknown_provider(): void
    {
        $this->artisan('preview:capture', ['provider' => 'missing'])
            ->expectsOutputToContain('Unknown preview provider [missing].')
            ->assertExitCode(1);
    }

    public function test_hmac_capture_requires_signature_header_option(): void
    {
        $this->artisan('preview:capture', [
            'provider' => 'hmac',
            '--path' => '/webhook',
        ])
            ->expectsOutput('The hmac provider requires --signature-header.')
            ->assertExitCode(1);
    }

    public function test_capture_command_opens_tunnel_when_transport_is_provided_without_synthetic_data(): void
    {
        $transport = new RecordingTunnelTransport('https://public.example.test');
        $registry = new TransportRegistry();
        $registry->register('cloudflare', $transport);
        $this->app->instance(TransportRegistry::class, $registry);

        $this->artisan('preview:capture', [
            'provider' => 'generic',
            '--transport' => 'cloudflare',
            '--local-url' => 'http://127.0.0.1:9000',
        ])
            ->expectsOutput('Capture URL: https://public.example.test/__preview/capture/generic')
            ->assertExitCode(0);

        $this->assertSame(['http://127.0.0.1:9000'], $transport->openedLocalUrls);
        $this->assertSame(['https://public.example.test'], $transport->closedPublicUrls);
        $this->assertCount(0, app(CaptureRepository::class)->all());
    }

    public function test_explicit_synthetic_capture_options_take_precedence_over_transport(): void
    {
        $transport = new RecordingTunnelTransport('https://public.example.test');
        $registry = new TransportRegistry();
        $registry->register('cloudflare', $transport);
        $this->app->instance(TransportRegistry::class, $registry);

        $this->artisan('preview:capture', [
            'provider' => 'generic',
            '--transport' => 'cloudflare',
            '--path' => '/webhooks/orders',
            '--body' => '{"id":1}',
        ])
            ->expectsOutputToContain('Captured')
            ->expectsOutputToContain('Endpoint: POST /webhooks/orders')
            ->assertExitCode(0);

        $this->assertSame([], $transport->openedLocalUrls);
        $this->assertCount(1, app(CaptureRepository::class)->all());
    }

    public function test_capture_command_fails_clearly_for_unknown_transport(): void
    {
        $this->app->instance(TransportRegistry::class, new TransportRegistry());

        $this->artisan('preview:capture', [
            'provider' => 'generic',
            '--transport' => 'missing',
        ])
            ->expectsOutput('Unknown tunnel transport [missing].')
            ->assertExitCode(1);
    }

    public function test_capture_list_show_replay_fixture_and_test_commands_work_together(): void
    {
        $this->artisan('preview:capture', [
            'provider' => 'generic',
            '--path' => '/webhooks/orders',
            '--body' => '{"id":1}',
            '--header' => ['X-Preview-Event: order.created'],
        ])
            ->expectsOutputToContain('Captured')
            ->expectsOutputToContain('Endpoint: POST /webhooks/orders')
            ->assertExitCode(0);

        $record = app(CaptureRepository::class)->all()[0];
        $this->assertSame('generic', $record->provider);
        $this->assertSame('order.created', $record->eventType);

        $this->artisan('preview:capture:list')
            ->assertExitCode(0);

        $this->artisan('preview:capture:show', ['capture' => $record->id])
            ->assertExitCode(0);

        $this->artisan('preview:capture:replay', [
            'capture' => $record->id,
            '--exact' => true,
        ])
            ->expectsOutputToContain('Replay payload ready')
            ->expectsOutputToContain('Endpoint: POST /webhooks/orders')
            ->assertExitCode(0);

        $this->artisan('preview:capture:fixture', ['capture' => $record->id])
            ->expectsOutputToContain('Fixture generated')
            ->assertExitCode(0);

        $this->artisan('preview:capture:test', ['capture' => $record->id])
            ->expectsOutputToContain('Pest test generated')
            ->assertExitCode(0);
    }

    public function test_capture_replay_can_dispatch_to_http_target_with_injected_transport(): void
    {
        $requests = [];

        $this->app->instance(HttpReplayDispatcher::class, new HttpReplayDispatcher(
            function (string $url, string $method, array $headers, string $body, array $payload) use (&$requests): ReplayResult {
                $requests[] = compact('url', 'method', 'headers', 'body', 'payload');

                return new ReplayResult(204, '');
            },
        ));

        $this->artisan('preview:capture', [
            'provider' => 'generic',
            '--path' => '/webhooks/orders',
            '--body' => '{"id":1}',
            '--header' => ['X-Preview-Event: order.created'],
        ])->assertExitCode(0);

        $record = app(CaptureRepository::class)->all()[0];

        $this->artisan('preview:capture:replay', [
            'capture' => $record->id,
            '--exact' => true,
            '--send-to' => 'https://receiver.test',
        ])
            ->expectsOutputToContain('Replay payload ready')
            ->expectsOutput('Replay HTTP status: 204')
            ->expectsOutput('Replay dispatch: success')
            ->assertExitCode(0);

        $this->assertSame('https://receiver.test/webhooks/orders', $requests[0]['url']);
        $this->assertSame('POST', $requests[0]['method']);
        $this->assertSame('{"id":1}', $requests[0]['body']);
        $this->assertContains('X-Preview-Event: order.created', $requests[0]['headers']);
    }
}

final class RecordingTunnelTransport implements TunnelTransport
{
    /** @var list<string> */
    public array $openedLocalUrls = [];

    /** @var list<string> */
    public array $closedPublicUrls = [];

    public function __construct(private readonly string $publicUrl)
    {
    }

    public function open(string $localUrl): TunnelHandle
    {
        $this->openedLocalUrls[] = $localUrl;

        return new TunnelHandle($this->publicUrl);
    }

    public function close(TunnelHandle $handle): void
    {
        $this->closedPublicUrls[] = $handle->publicUrl;
    }
}

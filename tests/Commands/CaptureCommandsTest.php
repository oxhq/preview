<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use Illuminate\Support\Facades\Artisan;
use Oxhq\Preview\Capture\CaptureRepository;
use Oxhq\Preview\Capture\HttpReplayDispatcher;
use Oxhq\Preview\Capture\ReplayResult;
use Oxhq\Preview\Core\Transport\StripeCliTunnelTransport;
use Oxhq\Preview\Core\Transport\TransportProcess;
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
        $this->app['config']->set('preview.live_enabled', true);
        $transport = new RecordingTunnelTransport('https://public.example.test');
        $registry = new TransportRegistry();
        $registry->register('cloudflare', $transport);
        $this->app->instance(TransportRegistry::class, $registry);

        $this->artisan('preview:capture', [
            'provider' => 'generic',
            '--transport' => 'cloudflare',
            '--local-url' => 'http://127.0.0.1:9000',
            '--live' => true,
        ])
            ->expectsOutput('Capture URL: https://public.example.test/__preview/capture/generic')
            ->assertExitCode(0);

        $this->assertSame(['http://127.0.0.1:9000'], $transport->openedLocalUrls);
        $this->assertSame(['https://public.example.test'], $transport->closedPublicUrls);
        $this->assertCount(0, app(CaptureRepository::class)->all());
    }

    public function test_capture_command_can_start_stripe_cli_transport_when_live_opt_in_is_enabled(): void
    {
        $this->app['config']->set('preview.live_enabled', true);
        $process = new CaptureFakeTransportProcess(pid: 9876);
        $factory = new CaptureRecordingProcessFactory($process);
        $transport = new StripeCliTunnelTransport(
            processFactory: $factory(...),
            binary: 'C:\\Tools\\Stripe CLI\\stripe.exe',
            capturePathTemplate: '/__preview/capture/{provider}',
        );
        $registry = new TransportRegistry();
        $registry->register('stripe-cli', $transport);
        $this->app->instance(TransportRegistry::class, $registry);

        $this->artisan('preview:capture', [
            'provider' => 'stripe',
            '--transport' => 'stripe-cli',
            '--local-url' => 'http://127.0.0.1:9000',
            '--live' => true,
        ])
            ->expectsOutput('Capture URL: http://127.0.0.1:9000/__preview/capture/stripe')
            ->assertExitCode(0);

        $this->assertSame(
            ['C:\\Tools\\Stripe CLI\\stripe.exe', 'listen', '--forward-to', 'http://127.0.0.1:9000/__preview/capture/stripe'],
            $factory->commands[0],
        );
        $this->assertTrue($process->started);
        $this->assertTrue($process->stopped);
        $this->assertCount(0, app(CaptureRepository::class)->all());
    }

    public function test_tunnel_capture_requires_explicit_live_option(): void
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
            ->expectsOutput('Live tunnel capture requires --live and preview.live_enabled=true.')
            ->assertExitCode(1);

        $this->assertSame([], $transport->openedLocalUrls);
    }

    public function test_tunnel_capture_rejects_live_option_when_live_config_is_disabled(): void
    {
        $transport = new RecordingTunnelTransport('https://public.example.test');
        $registry = new TransportRegistry();
        $registry->register('cloudflare', $transport);
        $this->app->instance(TransportRegistry::class, $registry);

        $this->artisan('preview:capture', [
            'provider' => 'generic',
            '--transport' => 'cloudflare',
            '--live' => true,
        ])
            ->expectsOutput('Live capture is disabled. Enable preview.live_enabled and pass --live explicitly.')
            ->assertExitCode(1);

        $this->assertSame([], $transport->openedLocalUrls);
    }

    public function test_tunnel_capture_url_uses_configured_http_capture_path(): void
    {
        $this->app['config']->set('preview.live_enabled', true);
        $this->app['config']->set('preview.http_capture.path', '/preview/inbound/{provider}');

        $transport = new RecordingTunnelTransport('https://public.example.test');
        $registry = new TransportRegistry();
        $registry->register('cloudflare', $transport);
        $this->app->instance(TransportRegistry::class, $registry);

        $this->artisan('preview:capture', [
            'provider' => 'generic',
            '--transport' => 'cloudflare',
            '--local-url' => 'http://127.0.0.1:9000',
            '--live' => true,
        ])
            ->expectsOutput('Capture URL: https://public.example.test/preview/inbound/generic')
            ->assertExitCode(0);
    }

    public function test_hmac_tunnel_capture_url_carries_signature_header_for_http_endpoint(): void
    {
        $this->app['config']->set('preview.live_enabled', true);
        $transport = new RecordingTunnelTransport('https://public.example.test');
        $registry = new TransportRegistry();
        $registry->register('cloudflare', $transport);
        $this->app->instance(TransportRegistry::class, $registry);

        $this->artisan('preview:capture', [
            'provider' => 'hmac',
            '--signature-header' => 'X-Custom-Signature',
            '--transport' => 'cloudflare',
            '--local-url' => 'http://127.0.0.1:9000',
            '--live' => true,
        ])
            ->expectsOutput('Capture URL: https://public.example.test/__preview/capture/hmac?signature_header=X-Custom-Signature')
            ->assertExitCode(0);

        $this->assertSame(['http://127.0.0.1:9000'], $transport->openedLocalUrls);
        $this->assertSame(['https://public.example.test'], $transport->closedPublicUrls);
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
        $this->app['config']->set('preview.live_enabled', true);
        $this->app->instance(TransportRegistry::class, new TransportRegistry());

        $this->artisan('preview:capture', [
            'provider' => 'generic',
            '--transport' => 'missing',
            '--live' => true,
        ])
            ->expectsOutput('Unknown tunnel transport [missing].')
            ->assertExitCode(1);
    }

    public function test_capture_command_rejects_negative_tunnel_hold_seconds(): void
    {
        $this->app['config']->set('preview.live_enabled', true);

        $this->artisan('preview:capture', [
            'provider' => 'generic',
            '--transport' => 'cloudflare',
            '--live' => true,
            '--hold-seconds' => '-1',
        ])
            ->expectsOutput('The --hold-seconds option must be zero or greater.')
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

    public function test_capture_fixture_json_outputs_generated_fixture_metadata_without_body_or_secrets(): void
    {
        $this->artisan('preview:capture', [
            'provider' => 'generic',
            '--path' => '/webhooks/orders',
            '--body' => '{"id":1}',
            '--header' => ['X-Preview-Event: order.created', 'Authorization: Bearer fixture-secret'],
        ])->assertExitCode(0);

        $record = app(CaptureRepository::class)->all()[0];
        $exitCode = Artisan::call('preview:capture:fixture', [
            'capture' => $record->id,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($record->id, $payload['id']);
        $this->assertSame('generic', $payload['provider']);
        $this->assertSame(str_replace('\\', '/', sys_get_temp_dir()).'/preview-tests/fixtures/generic/order.created/fixture.php', str_replace('\\', '/', $payload['fixture_path']));
        $this->assertFalse($payload['can_sign']);
        $this->assertArrayNotHasKey('raw_body', $payload);
        $this->assertStringNotContainsString('fixture-secret', Artisan::output());
        $this->assertStringNotContainsString('{"id":1}', Artisan::output());
    }

    public function test_capture_test_json_outputs_generated_test_metadata_and_signing_support(): void
    {
        $body = '{"event":"created"}';
        $signature = hash_hmac('sha256', $body, 'test-secret');

        $this->artisan('preview:capture', [
            'provider' => 'hmac',
            '--signature-header' => 'X-Custom-Signature',
            '--path' => '/webhooks/signed',
            '--body' => $body,
            '--header' => ['X-Custom-Signature: '.$signature, 'Authorization: Bearer test-secret-value'],
        ])->assertExitCode(0);

        $record = app(CaptureRepository::class)->all()[0];
        $exitCode = Artisan::call('preview:capture:test', [
            'capture' => $record->id,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($record->id, $payload['id']);
        $this->assertSame('hmac', $payload['provider']);
        $this->assertSame(str_replace('\\', '/', sys_get_temp_dir()).'/preview-tests/tests/Preview/hmac-generic-captureTest.php', str_replace('\\', '/', $payload['test_path']));
        $this->assertTrue($payload['can_sign']);
        $this->assertArrayNotHasKey('raw_body', $payload);
        $this->assertStringNotContainsString('test-secret-value', Artisan::output());
        $this->assertStringNotContainsString($body, Artisan::output());
    }

    public function test_capture_list_json_outputs_redacted_capture_summaries(): void
    {
        $this->artisan('preview:capture', [
            'provider' => 'generic',
            '--path' => '/webhooks/orders',
            '--body' => '{"id":1}',
            '--header' => ['X-Preview-Event: order.created', 'Authorization: Bearer list-secret'],
        ])->assertExitCode(0);

        $exitCode = Artisan::call('preview:capture:list', [
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $summaries = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($summaries);
        $this->assertCount(1, $summaries);
        $this->assertSame('generic', $summaries[0]['provider']);
        $this->assertSame('order.created', $summaries[0]['event_type']);
        $this->assertSame('POST', $summaries[0]['method']);
        $this->assertSame('/webhooks/orders', $summaries[0]['path']);
        $this->assertSame('[redacted]', $summaries[0]['headers']['Authorization']);
        $this->assertArrayNotHasKey('raw_body', $summaries[0]);
        $this->assertArrayNotHasKey('raw_body_path', $summaries[0]);
        $this->assertStringNotContainsString('list-secret', Artisan::output());
    }

    public function test_capture_show_accepts_explicit_json_option(): void
    {
        $this->artisan('preview:capture', [
            'provider' => 'generic',
            '--path' => '/webhooks/orders',
            '--body' => '{"id":1}',
        ])->assertExitCode(0);

        $record = app(CaptureRepository::class)->all()[0];
        $exitCode = Artisan::call('preview:capture:show', [
            'capture' => $record->id,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $capture = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($record->id, $capture['id']);
        $this->assertSame('generic', $capture['provider']);
    }

    public function test_capture_replay_json_outputs_safe_payload_metadata_without_raw_body(): void
    {
        $this->artisan('preview:capture', [
            'provider' => 'generic',
            '--path' => '/webhooks/orders',
            '--body' => '{"id":1}',
            '--header' => ['X-Preview-Event: order.created', 'Authorization: Bearer replay-secret'],
        ])->assertExitCode(0);

        $record = app(CaptureRepository::class)->all()[0];
        $exitCode = Artisan::call('preview:capture:replay', [
            'capture' => $record->id,
            '--exact' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($record->id, $payload['id']);
        $this->assertSame('exact', $payload['mode']);
        $this->assertSame('generic', $payload['provider']);
        $this->assertSame('POST', $payload['method']);
        $this->assertSame('/webhooks/orders', $payload['path']);
        $this->assertSame('[redacted]', $payload['headers']['Authorization']);
        $this->assertSame(8, $payload['raw_body_bytes']);
        $this->assertArrayNotHasKey('raw_body', $payload);
        $this->assertStringNotContainsString('replay-secret', Artisan::output());
        $this->assertStringNotContainsString('{"id":1}', Artisan::output());
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
            '--header' => ['X-Preview-Event: order.created', 'Authorization: Bearer exact-secret'],
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
        $this->assertContains('Authorization: Bearer exact-secret', $requests[0]['headers']);

        $record = app(CaptureRepository::class)->find($record->id);
        $this->assertSame('[redacted]', $record->headers['Authorization']);
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

final class CaptureRecordingProcessFactory
{
    /** @var list<list<string>> */
    public array $commands = [];

    public function __construct(private readonly TransportProcess $process)
    {
    }

    /** @param list<string> $command */
    public function __invoke(array $command): TransportProcess
    {
        $this->commands[] = $command;

        return $this->process;
    }
}

final class CaptureFakeTransportProcess implements TransportProcess
{
    public bool $started = false;
    public bool $stopped = false;

    public function __construct(private readonly ?int $pid = null)
    {
    }

    public function start(): void
    {
        $this->started = true;
    }

    public function isRunning(): bool
    {
        return ! $this->stopped;
    }

    public function getIncrementalOutput(): string
    {
        return '';
    }

    public function getIncrementalErrorOutput(): string
    {
        return '';
    }

    public function stop(float $timeout = 10.0): ?int
    {
        $this->stopped = true;

        return 0;
    }

    public function getPid(): ?int
    {
        return $this->pid;
    }
}

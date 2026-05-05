<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use Oxhq\Preview\Core\Transport\CloudflareTunnelTransport;
use Oxhq\Preview\Core\Transport\NgrokTunnelTransport;
use Oxhq\Preview\Core\Transport\StripeCliTunnelTransport;
use Oxhq\Preview\Core\Transport\TransportRegistry;
use Oxhq\Preview\Core\Transport\TunnelHandle;
use Oxhq\Preview\Core\Transport\TunnelTransport;
use Oxhq\Preview\Tests\TestCase;

final class TransportListCommandTest extends TestCase
{
    public function test_it_lists_configured_transport_names_and_implementation_classes(): void
    {
        $this->artisan('preview:transport:list')
            ->expectsOutput('Preview transports:')
            ->expectsOutput(' - cloudflare: '.CloudflareTunnelTransport::class)
            ->expectsOutput(' - ngrok: '.NgrokTunnelTransport::class)
            ->expectsOutput(' - stripe-cli: '.StripeCliTunnelTransport::class)
            ->assertExitCode(0);
    }

    public function test_it_outputs_machine_readable_json(): void
    {
        $this->artisan('preview:transport:list', ['--json' => true])
            ->expectsOutput(json_encode([
                [
                    'name' => 'cloudflare',
                    'class' => CloudflareTunnelTransport::class,
                ],
                [
                    'name' => 'ngrok',
                    'class' => NgrokTunnelTransport::class,
                ],
                [
                    'name' => 'stripe-cli',
                    'class' => StripeCliTunnelTransport::class,
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
            ->assertExitCode(0);
    }

    public function test_it_does_not_open_registered_transports(): void
    {
        $transport = new ListCommandRecordingTunnelTransport();
        $registry = new TransportRegistry();
        $registry->register('custom', $transport);
        $this->app->instance(TransportRegistry::class, $registry);

        $this->artisan('preview:transport:list')
            ->expectsOutput('Preview transports:')
            ->expectsOutput(' - custom: '.ListCommandRecordingTunnelTransport::class)
            ->assertExitCode(0);

        $this->assertSame([], $transport->openedLocalUrls);
    }
}

final class ListCommandRecordingTunnelTransport implements TunnelTransport
{
    /** @var list<string> */
    public array $openedLocalUrls = [];

    public function open(string $localUrl): TunnelHandle
    {
        $this->openedLocalUrls[] = $localUrl;

        return new TunnelHandle('https://public.example.test');
    }

    public function close(TunnelHandle $handle): void
    {
    }
}

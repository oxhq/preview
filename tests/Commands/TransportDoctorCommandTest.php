<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use Oxhq\Preview\Core\Transport\CloudflareTunnelTransport;
use Oxhq\Preview\Core\Transport\NgrokTunnelTransport;
use Oxhq\Preview\Core\Transport\TransportRegistry;
use Oxhq\Preview\Core\Transport\TunnelHandle;
use Oxhq\Preview\Core\Transport\TunnelTransport;
use Oxhq\Preview\Tests\TestCase;

final class TransportDoctorCommandTest extends TestCase
{
    public function test_it_reports_configured_transports_and_binary_discovery_without_network(): void
    {
        $cloudflared = $this->tempBinary('cloudflared');
        $missingNgrok = sys_get_temp_dir().DIRECTORY_SEPARATOR.'preview-tests'.DIRECTORY_SEPARATOR.'missing-ngrok.exe';

        config()->set('preview.transport_binaries.cloudflare', $cloudflared);
        config()->set('preview.transport_binaries.ngrok', $missingNgrok);
        config()->set('preview.transports', [
            'cloudflare' => CloudflareTunnelTransport::class,
            'ngrok' => NgrokTunnelTransport::class,
        ]);
        $this->app->forgetInstance(TransportRegistry::class);

        $this->artisan('preview:transport:doctor')
            ->expectsOutput('Preview transport diagnostics:')
            ->expectsOutput(' - cloudflare: '.CloudflareTunnelTransport::class)
            ->expectsOutput('   Binary: '.$cloudflared)
            ->expectsOutput('   Discoverable: yes (absolute path)')
            ->expectsOutput(' - ngrok: '.NgrokTunnelTransport::class)
            ->expectsOutput('   Binary: '.$missingNgrok)
            ->expectsOutput('   Discoverable: no')
            ->assertExitCode(0);
    }

    public function test_it_outputs_machine_readable_json(): void
    {
        $cloudflared = $this->tempBinary('cloudflared');
        $missingNgrok = sys_get_temp_dir().DIRECTORY_SEPARATOR.'preview-tests'.DIRECTORY_SEPARATOR.'missing-ngrok.exe';

        config()->set('preview.transport_binaries.cloudflare', $cloudflared);
        config()->set('preview.transport_binaries.ngrok', $missingNgrok);
        config()->set('preview.transports', [
            'cloudflare' => CloudflareTunnelTransport::class,
            'ngrok' => NgrokTunnelTransport::class,
        ]);
        $this->app->forgetInstance(TransportRegistry::class);

        $this->artisan('preview:transport:doctor', ['--json' => true])
            ->expectsOutput(json_encode([
                [
                    'name' => 'cloudflare',
                    'class' => CloudflareTunnelTransport::class,
                    'binary' => $cloudflared,
                    'binary_found' => true,
                    'binary_path' => $cloudflared,
                    'binary_source' => 'absolute',
                ],
                [
                    'name' => 'ngrok',
                    'class' => NgrokTunnelTransport::class,
                    'binary' => $missingNgrok,
                    'binary_found' => false,
                    'binary_path' => null,
                    'binary_source' => null,
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
            ->assertExitCode(0);
    }

    public function test_it_does_not_open_registered_transports(): void
    {
        $transport = new DoctorCommandRecordingTunnelTransport();
        $registry = new TransportRegistry();
        $registry->register('custom', $transport);
        $this->app->instance(TransportRegistry::class, $registry);

        $this->artisan('preview:transport:doctor')
            ->expectsOutput('Preview transport diagnostics:')
            ->expectsOutput(' - custom: '.DoctorCommandRecordingTunnelTransport::class)
            ->expectsOutput('   Binary: not configured')
            ->assertExitCode(0);

        $this->assertSame([], $transport->openedLocalUrls);
    }

    private function tempBinary(string $name): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'preview-tests'.DIRECTORY_SEPARATOR.'bin';

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $path = $directory.DIRECTORY_SEPARATOR.$name.(PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
        file_put_contents($path, '');
        chmod($path, 0755);

        return $path;
    }
}

final class DoctorCommandRecordingTunnelTransport implements TunnelTransport
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

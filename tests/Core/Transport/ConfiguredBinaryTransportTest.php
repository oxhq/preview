<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Core\Transport;

use Oxhq\Preview\Core\Transport\CloudflareTunnelTransport;
use Oxhq\Preview\Core\Transport\NgrokTunnelTransport;
use PHPUnit\Framework\TestCase;

final class ConfiguredBinaryTransportTest extends TestCase
{
    public function test_cloudflare_transport_uses_configured_binary_path(): void
    {
        $process = new FakeTransportProcess(
            output: 'https://preview-demo.trycloudflare.com Registered tunnel connection',
            pid: 1234,
        );
        $factory = new RecordingProcessFactory($process);
        $transport = new CloudflareTunnelTransport(
            processFactory: $factory(...),
            urlTimeoutSeconds: 0.1,
            binary: 'C:\\Program Files (x86)\\cloudflared\\cloudflared.exe',
        );

        $transport->open('http://127.0.0.1:8000');

        $this->assertSame(
            ['C:\\Program Files (x86)\\cloudflared\\cloudflared.exe', 'tunnel', '--url', 'http://127.0.0.1:8000'],
            $factory->commands[0],
        );
    }

    public function test_ngrok_transport_uses_configured_binary_path(): void
    {
        $process = new FakeTransportProcess(
            output: 'Forwarding https://abc.ngrok-free.app -> http://127.0.0.1:8000',
        );
        $factory = new RecordingProcessFactory($process);
        $transport = new NgrokTunnelTransport(
            processFactory: $factory(...),
            urlTimeoutSeconds: 0.1,
            binary: 'C:\\Tools\\ngrok.exe',
        );

        $transport->open('http://127.0.0.1:8000');

        $this->assertSame(
            ['C:\\Tools\\ngrok.exe', 'http', 'http://127.0.0.1:8000'],
            $factory->commands[0],
        );
    }
}

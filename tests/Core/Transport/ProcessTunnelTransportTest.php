<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Core\Transport;

use Oxhq\Preview\Core\Transport\CloudflareTunnelTransport;
use Oxhq\Preview\Core\Transport\NgrokTunnelTransport;
use Oxhq\Preview\Core\Transport\TransportProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProcessTunnelTransportTest extends TestCase
{
    public function test_cloudflare_tunnel_starts_expected_command_and_parses_public_url(): void
    {
        $process = new FakeTransportProcess(
            output: '2026-05-02 INF Your quick Tunnel has been created! https://preview-demo.trycloudflare.com 2026-05-02 INF Registered tunnel connection',
            pid: 1234,
        );
        $factory = new RecordingProcessFactory($process);
        $transport = new CloudflareTunnelTransport($factory(...), urlTimeoutSeconds: 0.1);

        $handle = $transport->open('http://127.0.0.1:8000');

        $this->assertSame(['cloudflared', 'tunnel', '--url', 'http://127.0.0.1:8000'], $factory->commands[0]);
        $this->assertTrue($process->started);
        $this->assertSame('https://preview-demo.trycloudflare.com', $handle->publicUrl);
        $this->assertSame(1234, $handle->processId);
    }

    public function test_cloudflare_tunnel_waits_for_registered_connection_after_public_url(): void
    {
        $process = new FakeTransportProcess(
            output: '2026-05-02 INF Your quick Tunnel has been created! https://preview-demo.trycloudflare.com',
        );
        $transport = new CloudflareTunnelTransport(
            new RecordingProcessFactory($process)(...),
            urlTimeoutSeconds: 0.01,
            pollIntervalMicroseconds: 1,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to detect ready public tunnel URL for [cloudflare]');
        $this->expectExceptionMessage('preview-demo.trycloudflare.com');

        $transport->open('http://localhost:8000');
    }

    public function test_cloudflare_tunnel_default_timeout_allows_slow_quick_tunnel_startup(): void
    {
        $process = new DelayedOutputTransportProcess(
            output: '2026-05-02 INF Your quick Tunnel has been created! https://preview-demo.trycloudflare.com 2026-05-02 INF Registered tunnel connection',
            delaySeconds: 2.2,
        );

        $transport = new CloudflareTunnelTransport(
            new RecordingProcessFactory($process)(...),
            pollIntervalMicroseconds: 50_000,
        );

        $handle = $transport->open('http://localhost:8000');

        $this->assertSame('https://preview-demo.trycloudflare.com', $handle->publicUrl);
    }

    public function test_ngrok_tunnel_starts_expected_command_and_parses_forwarding_url(): void
    {
        $process = new FakeTransportProcess(
            output: 'Forwarding https://abc-123.ngrok-free.app -> http://localhost:8000',
        );
        $factory = new RecordingProcessFactory($process);
        $transport = new NgrokTunnelTransport($factory(...), urlTimeoutSeconds: 0.1);

        $handle = $transport->open('http://localhost:8000');

        $this->assertSame(['ngrok', 'http', 'http://localhost:8000'], $factory->commands[0]);
        $this->assertSame('https://abc-123.ngrok-free.app', $handle->publicUrl);
    }

    public function test_open_throws_when_no_public_url_appears_before_timeout(): void
    {
        $process = new FakeTransportProcess(output: 'starting tunnel without url');
        $transport = new CloudflareTunnelTransport(
            new RecordingProcessFactory($process)(...),
            urlTimeoutSeconds: 0.01,
            pollIntervalMicroseconds: 1,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to detect ready public tunnel URL for [cloudflare]');
        $this->expectExceptionMessage('starting tunnel without url');

        $transport->open('http://localhost:8000');
    }

    public function test_close_stops_the_process_retained_on_the_handle(): void
    {
        $process = new FakeTransportProcess(
            output: 'Forwarding https://abc-123.ngrok-free.app -> http://localhost:8000',
        );
        $transport = new NgrokTunnelTransport(new RecordingProcessFactory($process)(...), urlTimeoutSeconds: 0.1);

        $handle = $transport->open('http://localhost:8000');
        $transport->close($handle);

        $this->assertTrue($process->stopped);
    }
}

final class RecordingProcessFactory
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

final class FakeTransportProcess implements TransportProcess
{
    public bool $started = false;
    public bool $stopped = false;
    private bool $outputRead = false;

    public function __construct(
        private readonly string $output = '',
        private readonly string $errorOutput = '',
        private readonly ?int $pid = null,
    ) {
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
        if ($this->outputRead) {
            return '';
        }

        $this->outputRead = true;

        return $this->output;
    }

    public function getIncrementalErrorOutput(): string
    {
        return $this->errorOutput;
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

final class DelayedOutputTransportProcess implements TransportProcess
{
    public bool $started = false;
    public bool $stopped = false;
    private float $startedAt = 0.0;
    private bool $outputRead = false;

    public function __construct(
        private readonly string $output,
        private readonly float $delaySeconds,
    ) {
    }

    public function start(): void
    {
        $this->started = true;
        $this->startedAt = microtime(true);
    }

    public function isRunning(): bool
    {
        return ! $this->stopped;
    }

    public function getIncrementalOutput(): string
    {
        if ($this->outputRead || ! $this->started || (microtime(true) - $this->startedAt) < $this->delaySeconds) {
            return '';
        }

        $this->outputRead = true;

        return $this->output;
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
        return null;
    }
}

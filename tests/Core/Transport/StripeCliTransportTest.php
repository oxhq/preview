<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Core\Transport;

use InvalidArgumentException;
use Oxhq\Preview\Core\Transport\StripeCliTunnelTransport;
use Oxhq\Preview\Core\Transport\TransportProcess;
use Oxhq\Preview\Core\Transport\TransportRegistry;
use Oxhq\Preview\Tests\TestCase;
use RuntimeException;

final class StripeCliTransportTest extends TestCase
{
    public function test_stripe_cli_transport_starts_listener_forwarding_to_stripe_capture_endpoint(): void
    {
        $process = new StripeFakeTransportProcess(pid: 4242);
        $factory = new StripeRecordingProcessFactory($process);
        $transport = new StripeCliTunnelTransport(
            processFactory: $factory(...),
            binary: 'stripe',
            capturePathTemplate: '/__preview/capture/{provider}',
        );

        $handle = $transport->open('http://127.0.0.1:8000/');

        $this->assertSame(
            ['stripe', 'listen', '--forward-to', 'http://127.0.0.1:8000/__preview/capture/stripe'],
            $factory->commands[0],
        );
        $this->assertTrue($process->started);
        $this->assertSame('http://127.0.0.1:8000', $handle->publicUrl);
        $this->assertSame(4242, $handle->processId);
        $this->assertSame('http://127.0.0.1:8000/__preview/capture/stripe', $handle->metadata['forwarded_to']);
    }

    public function test_stripe_cli_transport_uses_configured_windows_binary_path(): void
    {
        $process = new StripeFakeTransportProcess();
        $factory = new StripeRecordingProcessFactory($process);
        $transport = new StripeCliTunnelTransport(
            processFactory: $factory(...),
            binary: 'C:\\Tools\\Stripe CLI\\stripe.exe',
            capturePathTemplate: '/__preview/capture/{provider}',
        );

        $transport->open('http://127.0.0.1:8000');

        $this->assertSame(
            ['C:\\Tools\\Stripe CLI\\stripe.exe', 'listen', '--forward-to', 'http://127.0.0.1:8000/__preview/capture/stripe'],
            $factory->commands[0],
        );
    }

    public function test_stripe_cli_transport_rejects_missing_local_url(): void
    {
        $factory = new StripeRecordingProcessFactory(new StripeFakeTransportProcess());
        $transport = new StripeCliTunnelTransport(
            processFactory: $factory(...),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Stripe CLI transport requires a local URL.');

        $transport->open('');
    }

    public function test_stripe_cli_transport_reports_process_start_failures_with_binary_name(): void
    {
        $process = new StripeFakeTransportProcess(
            startException: new RuntimeException('The system cannot find the file specified.'),
        );
        $factory = new StripeRecordingProcessFactory($process);
        $transport = new StripeCliTunnelTransport(
            processFactory: $factory(...),
            binary: 'C:\\Missing\\stripe.exe',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to start stripe-cli listener using binary [C:\\Missing\\stripe.exe]');
        $this->expectExceptionMessage('The system cannot find the file specified.');

        $transport->open('http://127.0.0.1:8000');
    }

    public function test_stripe_cli_transport_is_registered_from_default_config(): void
    {
        $transport = app(TransportRegistry::class)->get('stripe-cli');

        $this->assertInstanceOf(StripeCliTunnelTransport::class, $transport);
    }
}

final class StripeRecordingProcessFactory
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

final class StripeFakeTransportProcess implements TransportProcess
{
    public bool $started = false;
    public bool $stopped = false;

    public function __construct(
        private readonly ?int $pid = null,
        private readonly ?RuntimeException $startException = null,
    ) {
    }

    public function start(): void
    {
        if ($this->startException !== null) {
            throw $this->startException;
        }

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

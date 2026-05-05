<?php

declare(strict_types=1);

namespace Oxhq\Preview\Core\Transport;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

class StripeCliTunnelTransport implements TunnelTransport
{
    /** @var callable(list<string>): TransportProcess */
    private $processFactory;

    /**
     * @param null|callable(list<string>): TransportProcess $processFactory
     */
    public function __construct(
        ?callable $processFactory = null,
        private readonly string $binary = 'stripe',
        private readonly string $capturePathTemplate = '/__preview/capture/{provider}',
    ) {
        $this->processFactory = $processFactory ?? SymfonyTransportProcess::fromCommand(...);
    }

    public function open(string $localUrl): TunnelHandle
    {
        $baseUrl = rtrim(trim($localUrl), '/');

        if ($baseUrl === '') {
            throw new InvalidArgumentException('Stripe CLI transport requires a local URL.');
        }

        $forwardTo = $baseUrl.$this->captureEndpointPath();
        $process = ($this->processFactory)([
            $this->binary,
            'listen',
            '--forward-to',
            $forwardTo,
        ]);

        try {
            $process->start();
        } catch (Throwable $exception) {
            throw new RuntimeException(
                "Unable to start stripe-cli listener using binary [{$this->binary}]: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        if (! $process->isRunning()) {
            $output = trim($process->getIncrementalOutput().' '.$process->getIncrementalErrorOutput());
            $message = 'Stripe CLI listener exited before it was ready.';

            if ($output !== '') {
                $message .= ' Last output: '.$this->excerpt($output);
            }

            throw new RuntimeException($message);
        }

        return new TunnelHandle(
            publicUrl: $baseUrl,
            processId: $process->getPid(),
            metadata: [
                'process' => $process,
                'forwarded_to' => $forwardTo,
            ],
        );
    }

    public function close(TunnelHandle $handle): void
    {
        $process = $handle->metadata['process'] ?? null;

        if ($process instanceof TransportProcess && $process->isRunning()) {
            $process->stop();
        }
    }

    private function captureEndpointPath(): string
    {
        if (str_contains($this->capturePathTemplate, '{provider}')) {
            $path = str_replace('{provider}', 'stripe', $this->capturePathTemplate);
        } else {
            $path = rtrim($this->capturePathTemplate, '/').'/stripe';
        }

        return '/'.ltrim($path, '/');
    }

    private function excerpt(string $output): string
    {
        $output = preg_replace('/\s+/', ' ', $output) ?? $output;

        return mb_substr($output, 0, 500);
    }
}

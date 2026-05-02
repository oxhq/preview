<?php

declare(strict_types=1);

namespace Oxhq\Preview\Core\Transport;

use RuntimeException;

abstract class ProcessTunnelTransport implements TunnelTransport
{
    /** @var callable(list<string>): TransportProcess */
    private $processFactory;

    /**
     * @param null|callable(list<string>): TransportProcess $processFactory
     */
    public function __construct(
        ?callable $processFactory = null,
        private readonly float $urlTimeoutSeconds = 2.0,
        private readonly int $pollIntervalMicroseconds = 50_000,
    ) {
        $this->processFactory = $processFactory ?? SymfonyTransportProcess::fromCommand(...);
    }

    public function open(string $localUrl): TunnelHandle
    {
        $process = ($this->processFactory)($this->command($localUrl));
        $process->start();

        $output = '';
        $deadline = microtime(true) + $this->urlTimeoutSeconds;

        do {
            $output .= $process->getIncrementalOutput();
            $output .= $process->getIncrementalErrorOutput();

            $publicUrl = $this->parsePublicUrl($output);

            if ($publicUrl !== null) {
                return new TunnelHandle(
                    publicUrl: $publicUrl,
                    processId: $process->getPid(),
                    metadata: ['process' => $process],
                );
            }

            if (! $process->isRunning()) {
                break;
            }

            if ($this->pollIntervalMicroseconds > 0) {
                usleep($this->pollIntervalMicroseconds);
            }
        } while (microtime(true) < $deadline);

        $timeout = rtrim(rtrim(sprintf('%.3F', $this->urlTimeoutSeconds), '0'), '.');

        $message = "Unable to detect public tunnel URL for [{$this->name()}] within {$timeout} seconds.";
        $excerpt = trim($output);

        if ($excerpt !== '') {
            $message .= ' Last output: '.$this->excerpt($excerpt);
        }

        throw new RuntimeException($message);
    }

    public function close(TunnelHandle $handle): void
    {
        $process = $handle->metadata['process'] ?? null;

        if ($process instanceof TransportProcess && $process->isRunning()) {
            $process->stop();
        }
    }

    /** @return list<string> */
    abstract protected function command(string $localUrl): array;

    abstract protected function parsePublicUrl(string $output): ?string;

    abstract protected function name(): string;

    private function excerpt(string $output): string
    {
        $output = preg_replace('/\s+/', ' ', $output) ?? $output;

        return mb_substr($output, 0, 500);
    }
}

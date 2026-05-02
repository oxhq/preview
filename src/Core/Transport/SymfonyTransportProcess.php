<?php

declare(strict_types=1);

namespace Oxhq\Preview\Core\Transport;

use Symfony\Component\Process\Process;

class SymfonyTransportProcess implements TransportProcess
{
    public function __construct(private readonly Process $process)
    {
    }

    /** @param list<string> $command */
    public static function fromCommand(array $command): self
    {
        return new self(new Process($command));
    }

    public function start(): void
    {
        $this->process->start();
    }

    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    public function getIncrementalOutput(): string
    {
        return $this->process->getIncrementalOutput();
    }

    public function getIncrementalErrorOutput(): string
    {
        return $this->process->getIncrementalErrorOutput();
    }

    public function stop(float $timeout = 10.0): ?int
    {
        return $this->process->stop($timeout);
    }

    public function getPid(): ?int
    {
        return $this->process->getPid();
    }
}

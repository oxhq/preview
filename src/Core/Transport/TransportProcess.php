<?php

declare(strict_types=1);

namespace Oxhq\Preview\Core\Transport;

interface TransportProcess
{
    public function start(): void;

    public function isRunning(): bool;

    public function getIncrementalOutput(): string;

    public function getIncrementalErrorOutput(): string;

    public function stop(float $timeout = 10.0): ?int;

    public function getPid(): ?int;
}

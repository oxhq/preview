<?php

declare(strict_types=1);

namespace Oxhq\Preview\Core\Transport;

interface TunnelTransport
{
    public function open(string $localUrl): TunnelHandle;

    public function close(TunnelHandle $handle): void;
}

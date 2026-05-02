<?php

declare(strict_types=1);

namespace Oxhq\Preview\Core\Transport;

use InvalidArgumentException;

class TransportRegistry
{
    /** @var array<string, TunnelTransport> */
    private array $transports = [];

    public function register(string $name, TunnelTransport $transport): void
    {
        $this->transports[strtolower($name)] = $transport;
    }

    public function get(string $name): TunnelTransport
    {
        $key = strtolower($name);

        if (! isset($this->transports[$key])) {
            throw new InvalidArgumentException("Unknown tunnel transport [{$name}].");
        }

        return $this->transports[$key];
    }
}

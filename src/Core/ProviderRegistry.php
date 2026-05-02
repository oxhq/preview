<?php

declare(strict_types=1);

namespace Oxhq\Preview\Core;

use InvalidArgumentException;
use Oxhq\Preview\Providers\PreviewProvider;

class ProviderRegistry
{
    /** @var array<string, PreviewProvider> */
    private array $providers = [];

    public function register(PreviewProvider $provider): void
    {
        $this->providers[$provider->name()] = $provider;
    }

    public function get(string $name): PreviewProvider
    {
        $key = strtolower($name);

        if (! isset($this->providers[$key])) {
            throw new InvalidArgumentException("Unknown preview provider [{$name}].");
        }

        return $this->providers[$key];
    }

    /** @return array<string, PreviewProvider> */
    public function all(): array
    {
        return $this->providers;
    }
}

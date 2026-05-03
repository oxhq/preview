<?php

declare(strict_types=1);

namespace Oxhq\Preview\Core;

use InvalidArgumentException;
use Oxhq\Preview\Providers\ContextualPreviewProvider;
use Oxhq\Preview\Providers\PreviewProvider;

class ProviderRegistry
{
    /** @var array<string, PreviewProvider> */
    private array $providers = [];

    public function register(PreviewProvider $provider): void
    {
        $this->providers[strtolower($provider->name())] = $provider;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function get(string $name, array $context = []): PreviewProvider
    {
        $key = strtolower($name);

        if (! isset($this->providers[$key])) {
            throw new InvalidArgumentException("Unknown preview provider [{$name}].");
        }

        $provider = $this->providers[$key];

        return $context !== [] && $provider instanceof ContextualPreviewProvider
            ? $provider->withRuntimeContext($context)
            : $provider;
    }

    /** @return array<string, PreviewProvider> */
    public function all(): array
    {
        return $this->providers;
    }
}

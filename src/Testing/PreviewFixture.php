<?php

declare(strict_types=1);

namespace Oxhq\Preview\Testing;

use Oxhq\Preview\Core\ProviderRegistry;
use RuntimeException;

final class PreviewFixture
{
    private string $provider = 'generic';

    private ?string $eventType = null;

    private string $endpoint = '/';

    private string $method = 'POST';

    private ?string $rawBodyPath = null;

    private ?string $headersPath = null;

    private string $signingMode = 'exact';

    private int $expectedStatus = 200;

    public static function load(string $path): self
    {
        $fixture = require $path;

        if (! $fixture instanceof self) {
            throw new RuntimeException("Fixture [{$path}] must return a PreviewFixture instance.");
        }

        return $fixture;
    }

    public static function provider(string $provider): self
    {
        $fixture = new self();
        $fixture->provider = $provider;

        return $fixture;
    }

    public static function generic(string $name): self
    {
        return self::fromConfiguredPath('generic', $name);
    }

    public static function stripe(string $name): self
    {
        return self::fromConfiguredPath('stripe', $name);
    }

    public function event(?string $eventType): self
    {
        $this->eventType = $eventType;

        return $this;
    }

    public function endpoint(string $endpoint): self
    {
        $this->endpoint = $endpoint;

        return $this;
    }

    public function method(string $method): self
    {
        $this->method = strtoupper($method);

        return $this;
    }

    public function rawBody(?string $path = null): self|string
    {
        if ($path !== null) {
            $this->rawBodyPath = $path;

            return $this;
        }

        return $this->readRawBody();
    }

    public function headers(?string $path = null): self|array
    {
        if ($path !== null) {
            $this->headersPath = $path;

            return $this;
        }

        return $this->readHeaders();
    }

    public function signing(string $mode): self
    {
        $this->signingMode = $mode;

        return $this;
    }

    public function assertsOk(): self
    {
        $this->expectedStatus = 200;

        return $this;
    }

    public function providerName(): string
    {
        return $this->provider;
    }

    public function eventType(): ?string
    {
        return $this->eventType;
    }

    public function endpointPath(): string
    {
        return $this->endpoint;
    }

    public function requestMethod(): string
    {
        return $this->method;
    }

    public function signingMode(): string
    {
        return $this->signingMode;
    }

    public function expectedStatus(): int
    {
        return $this->expectedStatus;
    }

    /**
     * @return array<string, mixed>
     */
    public function freshSignedHeaders(?ProviderRegistry $registry = null): array
    {
        $registry ??= $this->resolveProviderRegistry();
        $provider = $registry->get($this->provider);

        if (! $provider->canSign()) {
            throw new RuntimeException("Provider [{$this->provider}] cannot create fresh signed headers.");
        }

        return array_merge(
            $this->readHeaders(),
            $provider->sign($this->readRawBody(), $this->readHeaders()),
        );
    }

    /**
     * @param array<string, mixed>|null $headers
     * @return array<string, string>
     */
    public function serverHeaders(?array $headers = null): array
    {
        $headers ??= $this->readHeaders();
        $server = [];

        foreach ($headers as $name => $value) {
            $key = 'HTTP_'.strtoupper(str_replace('-', '_', (string) $name));
            $server[$key] = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
        }

        return $server;
    }

    private function readRawBody(): string
    {
        if ($this->rawBodyPath === null) {
            throw new RuntimeException('Fixture raw body path has not been configured.');
        }

        $body = file_get_contents($this->rawBodyPath);

        if ($body === false) {
            throw new RuntimeException("Fixture raw body [{$this->rawBodyPath}] could not be read.");
        }

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    private function readHeaders(): array
    {
        if ($this->headersPath === null) {
            return [];
        }

        $headers = require $this->headersPath;

        if (! is_array($headers)) {
            throw new RuntimeException("Fixture headers [{$this->headersPath}] must return an array.");
        }

        return $headers;
    }

    private function resolveProviderRegistry(): ProviderRegistry
    {
        if (! function_exists('app')) {
            throw new RuntimeException('A ProviderRegistry instance is required outside a Laravel application.');
        }

        return app(ProviderRegistry::class);
    }

    private static function fromConfiguredPath(string $provider, string $name): self
    {
        $root = function_exists('config') ? config('preview.fixture_path') : null;

        if (! is_string($root) || $root === '') {
            throw new RuntimeException('preview.fixture_path is not configured.');
        }

        return self::load(rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$provider.DIRECTORY_SEPARATOR.$name.DIRECTORY_SEPARATOR.'fixture.php');
    }
}

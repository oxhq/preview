<?php

declare(strict_types=1);

namespace Oxhq\Preview\Core\Transport;

class CloudflareTunnelTransport extends ProcessTunnelTransport
{
    /**
     * @param null|callable(list<string>): TransportProcess $processFactory
     */
    public function __construct(
        ?callable $processFactory = null,
        float $urlTimeoutSeconds = 2.0,
        int $pollIntervalMicroseconds = 50_000,
        private readonly string $binary = 'cloudflared',
        private readonly float $readinessDelaySeconds = 0.0,
    ) {
        parent::__construct($processFactory, $urlTimeoutSeconds, $pollIntervalMicroseconds);
    }

    /** @return list<string> */
    protected function command(string $localUrl): array
    {
        return [$this->binary, 'tunnel', '--url', $localUrl];
    }

    protected function parsePublicUrl(string $output): ?string
    {
        if (preg_match('/https:\/\/[a-zA-Z0-9.-]+\.trycloudflare\.com\b/', $output, $matches) === 1) {
            return $matches[0];
        }

        return null;
    }

    protected function name(): string
    {
        return 'cloudflare';
    }

    protected function isReady(string $output, string $publicUrl): bool
    {
        return str_contains($output, 'Registered tunnel connection');
    }

    protected function readinessDelaySeconds(): float
    {
        return $this->readinessDelaySeconds;
    }
}

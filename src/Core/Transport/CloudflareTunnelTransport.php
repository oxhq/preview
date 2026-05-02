<?php

declare(strict_types=1);

namespace Oxhq\Preview\Core\Transport;

class CloudflareTunnelTransport extends ProcessTunnelTransport
{
    /** @return list<string> */
    protected function command(string $localUrl): array
    {
        return ['cloudflared', 'tunnel', '--url', $localUrl];
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
}

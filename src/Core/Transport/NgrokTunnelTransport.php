<?php

declare(strict_types=1);

namespace Oxhq\Preview\Core\Transport;

class NgrokTunnelTransport extends ProcessTunnelTransport
{
    /** @return list<string> */
    protected function command(string $localUrl): array
    {
        return ['ngrok', 'http', $localUrl];
    }

    protected function parsePublicUrl(string $output): ?string
    {
        if (preg_match('/Forwarding\s+(https:\/\/[^\s]+)/i', $output, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    protected function name(): string
    {
        return 'ngrok';
    }
}

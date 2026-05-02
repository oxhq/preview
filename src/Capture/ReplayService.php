<?php

declare(strict_types=1);

namespace Oxhq\Preview\Capture;

use Oxhq\Preview\Core\ProviderRegistry;
use RuntimeException;

final class ReplayService
{
    public function __construct(
        private readonly CaptureRepository $captures,
        private readonly ProviderRegistry $providers,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function exact(string|CaptureRecord $capture): array
    {
        $record = is_string($capture) ? $this->captures->find($capture) : $capture;

        return $this->payload($record, $record->rawHeaders(), 'exact');
    }

    /**
     * @return array<string, mixed>
     */
    public function resign(string|CaptureRecord $capture): array
    {
        $record = is_string($capture) ? $this->captures->find($capture) : $capture;
        $provider = $this->providers->get($record->provider);

        if (! $provider->canSign()) {
            throw new RuntimeException("Provider [{$record->provider}] cannot re-sign captures. Use --exact instead.");
        }

        $headers = $record->rawHeaders();
        $headers = array_merge($headers, $provider->sign($record->rawBody(), $headers));

        return $this->payload($record, $headers, 'resign');
    }

    /**
     * @return array<string, mixed>
     */
    public function replay(string|CaptureRecord $capture, string $mode): array
    {
        return match ($mode) {
            'exact' => $this->exact($capture),
            'resign' => $this->resign($capture),
            default => throw new RuntimeException("Replay mode [{$mode}] is not supported."),
        };
    }

    /**
     * @param array<string, mixed> $headers
     * @return array<string, mixed>
     */
    private function payload(CaptureRecord $record, array $headers, string $mode): array
    {
        return [
            'mode' => $mode,
            'id' => $record->id,
            'provider' => $record->provider,
            'event_type' => $record->eventType,
            'method' => $record->method,
            'path' => $record->path,
            'query' => $record->query,
            'headers' => $headers,
            'raw_body' => $record->rawBody(),
            'captured_at' => $record->capturedAt->format(DATE_ATOM),
        ];
    }
}

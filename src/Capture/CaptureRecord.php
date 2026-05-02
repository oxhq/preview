<?php

declare(strict_types=1);

namespace Oxhq\Preview\Capture;

use DateTimeImmutable;
use RuntimeException;

final class CaptureRecord
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly string $provider,
        public readonly ?string $eventType,
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $headers,
        public readonly string $rawBodyPath,
        public readonly DateTimeImmutable $capturedAt,
        public readonly bool $verified,
        public readonly ?string $verificationMessage = null,
        public readonly array $metadata = [],
    ) {
    }

    public function rawBody(): string
    {
        $body = @file_get_contents($this->rawBodyPath);

        if ($body === false) {
            throw new RuntimeException("Raw body for capture [{$this->id}] could not be read.");
        }

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'event_type' => $this->eventType,
            'method' => $this->method,
            'path' => $this->path,
            'query' => $this->query,
            'headers' => $this->headers,
            'raw_body_path' => $this->rawBodyPath,
            'captured_at' => $this->capturedAt->format(DATE_ATOM),
            'verified' => $this->verified,
            'verification_message' => $this->verificationMessage,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            provider: (string) $data['provider'],
            eventType: isset($data['event_type']) ? (string) $data['event_type'] : null,
            method: (string) $data['method'],
            path: (string) $data['path'],
            query: is_array($data['query'] ?? null) ? $data['query'] : [],
            headers: is_array($data['headers'] ?? null) ? $data['headers'] : [],
            rawBodyPath: (string) $data['raw_body_path'],
            capturedAt: new DateTimeImmutable((string) $data['captured_at']),
            verified: (bool) $data['verified'],
            verificationMessage: isset($data['verification_message']) ? (string) $data['verification_message'] : null,
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
        );
    }
}

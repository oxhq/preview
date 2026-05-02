<?php

declare(strict_types=1);

namespace Oxhq\Preview\Capture;

use DateTimeImmutable;

final class PreviewRequest
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $headers
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $headers,
        public readonly string $rawBody,
        public readonly DateTimeImmutable $capturedAt = new DateTimeImmutable(),
        public readonly bool $verified = false,
        public readonly ?string $verificationMessage = null,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $headers
     */
    public static function make(
        string $provider,
        string $method,
        string $path,
        array $query = [],
        array $headers = [],
        string $rawBody = '',
    ): self {
        return new self(
            provider: $provider,
            method: strtoupper($method),
            path: $path === '' ? '/' : $path,
            query: $query,
            headers: $headers,
            rawBody: $rawBody,
        );
    }

    public function withVerification(bool $verified, ?string $message = null): self
    {
        return new self(
            provider: $this->provider,
            method: $this->method,
            path: $this->path,
            query: $this->query,
            headers: $this->headers,
            rawBody: $this->rawBody,
            capturedAt: $this->capturedAt,
            verified: $verified,
            verificationMessage: $message,
        );
    }
}

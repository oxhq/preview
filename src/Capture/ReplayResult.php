<?php

declare(strict_types=1);

namespace Oxhq\Preview\Capture;

final class ReplayResult
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    public function successful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }
}

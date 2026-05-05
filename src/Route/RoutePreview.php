<?php

declare(strict_types=1);

namespace Oxhq\Preview\Route;

use DateTimeImmutable;

final class RoutePreview
{
    /**
     * @param list<string> $methods
     * @param list<string> $middleware
     * @param array<string, string> $parameters
     * @param list<string> $fakes
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly string $name,
        public readonly string $uri,
        public readonly string $action,
        public readonly ?string $domain,
        public readonly array $methods,
        public readonly array $middleware,
        public readonly string $executionMethod,
        public readonly string $url,
        public readonly DateTimeImmutable $expiresAt,
        public readonly array $parameters = [],
        public readonly bool $readonlyDb = false,
        public readonly ?string $guard = null,
        public readonly array $fakes = [],
        public readonly array $warnings = [],
    ) {
    }
}

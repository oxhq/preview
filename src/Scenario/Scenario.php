<?php

declare(strict_types=1);

namespace Oxhq\Preview\Scenario;

final class Scenario
{
    /**
     * @param list<string> $routes
     * @param array<string, array<string, string>> $routeParameters
     * @param list<string> $captures
     * @param list<string> $fakes
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $seed = null,
        public readonly array $routes = [],
        public readonly array $routeParameters = [],
        public readonly array $captures = [],
        public readonly array $fakes = [],
        public readonly ?string $notes = null,
    ) {
    }
}

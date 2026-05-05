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
     * @param array<string, array{session?: array<string, string>, guard?: string, user_id?: string, user_model?: class-string|string, readonly_db?: bool, fakes?: list<string>}> $routeContext
     * @param array<string, array{status: int, output_contains?: string}> $routeExpectations
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $seed = null,
        public readonly array $routes = [],
        public readonly array $routeParameters = [],
        public readonly array $captures = [],
        public readonly array $fakes = [],
        public readonly ?string $notes = null,
        public readonly array $routeContext = [],
        public readonly array $routeExpectations = [],
    ) {
    }
}

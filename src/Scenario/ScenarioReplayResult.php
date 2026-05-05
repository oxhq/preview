<?php

declare(strict_types=1);

namespace Oxhq\Preview\Scenario;

use Oxhq\Preview\Capture\ReplayResult;

final class ScenarioReplayResult
{
    /**
     * @param list<array<string, mixed>> $captures
     * @param list<ReplayResult|null> $dispatches
     * @param list<ScenarioRouteResult> $routes
     */
    public function __construct(
        public readonly Scenario $scenario,
        public readonly string $mode,
        public readonly ?string $seed,
        public readonly array $captures,
        public readonly array $dispatches = [],
        public readonly array $routes = [],
    ) {
    }
}

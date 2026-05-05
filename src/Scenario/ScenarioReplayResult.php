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

    /**
     * @return array{seed: int, captures: int, dispatches: int, routes: int}
     */
    public function summaryCounts(): array
    {
        return [
            'seed' => $this->seed === null || trim($this->seed) === '' ? 0 : 1,
            'captures' => count($this->captures),
            'dispatches' => count(array_filter(
                $this->dispatches,
                static fn (?ReplayResult $dispatch): bool => $dispatch instanceof ReplayResult,
            )),
            'routes' => count($this->routes),
        ];
    }

    public function summaryLine(): string
    {
        $counts = $this->summaryCounts();

        return sprintf(
            'Summary: seed=%d captures=%d dispatches=%d routes=%d',
            $counts['seed'],
            $counts['captures'],
            $counts['dispatches'],
            $counts['routes'],
        );
    }
}

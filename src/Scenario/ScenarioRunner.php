<?php

declare(strict_types=1);

namespace Oxhq\Preview\Scenario;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Oxhq\Preview\Capture\HttpReplayDispatcher;
use Oxhq\Preview\Capture\ReplayService;
use Oxhq\Preview\Route\RoutePreviewService;
use RuntimeException;
use Throwable;

final class ScenarioRunner
{
    public function __construct(
        private readonly ScenarioRepository $scenarios,
        private readonly ReplayService $replay,
        private readonly HttpReplayDispatcher $dispatcher,
        private readonly RoutePreviewService $routes,
        private readonly Kernel $kernel,
    ) {
    }

    public function replay(string $scenarioName, string $mode, ?string $sendTo = null): ScenarioReplayResult
    {
        $scenario = $this->scenarios->find($scenarioName);

        if ($scenario === null) {
            throw new RuntimeException("Scenario [{$scenarioName}] was not found.");
        }

        if (! in_array($mode, ['exact', 'resign'], true)) {
            throw new RuntimeException("Scenario replay mode [{$mode}] is not supported.");
        }

        $this->runSeed($scenario);

        $captures = [];
        $dispatches = [];

        foreach ($scenario->captures as $captureId) {
            $payload = $this->replay->replay($captureId, $mode);
            $captures[] = $payload;
            $dispatches[] = $sendTo === null ? null : $this->dispatcher->dispatch($payload, $sendTo);
        }

        $routeResults = $this->executeRoutes($scenario);

        return new ScenarioReplayResult(
            scenario: $scenario,
            mode: $mode,
            seed: $scenario->seed,
            captures: $captures,
            dispatches: $dispatches,
            routes: $routeResults,
        );
    }

    /**
     * @return list<ScenarioRouteResult>
     */
    private function executeRoutes(Scenario $scenario): array
    {
        $results = [];

        foreach ($scenario->routes as $routeName) {
            $parameters = $this->routeParameters($scenario, $routeName);
            $preview = $this->routes->preview(
                routeName: $routeName,
                parameters: $parameters,
                fakes: $scenario->fakes,
            );
            $request = Request::create($preview->url, $preview->executionMethod);
            $response = $this->kernel->handle($request);

            try {
                $results[] = new ScenarioRouteResult($preview, $response);
            } finally {
                $this->kernel->terminate($request, $response);
            }
        }

        return $results;
    }

    /**
     * @return array<string, string>
     */
    private function routeParameters(Scenario $scenario, string $routeName): array
    {
        $parameters = $scenario->routeParameters[$routeName] ?? [];

        if (! is_array($parameters)) {
            return [];
        }

        $normalized = [];

        foreach ($parameters as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $normalized[$key] = (string) $value;
            }
        }

        return $normalized;
    }

    private function runSeed(Scenario $scenario): void
    {
        if ($scenario->seed === null || trim($scenario->seed) === '') {
            return;
        }

        if (! class_exists($scenario->seed)) {
            throw new RuntimeException("Scenario seed [{$scenario->seed}] was not found.");
        }

        try {
            $exitCode = Artisan::call('db:seed', [
                '--class' => $scenario->seed,
                '--force' => true,
            ]);
        } catch (Throwable $exception) {
            throw new RuntimeException("Scenario seed [{$scenario->seed}] failed: {$exception->getMessage()}", previous: $exception);
        }

        if ($exitCode !== 0) {
            throw new RuntimeException("Scenario seed [{$scenario->seed}] failed with exit code [{$exitCode}].");
        }
    }
}

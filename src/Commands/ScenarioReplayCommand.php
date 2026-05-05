<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use Oxhq\Preview\Capture\ReplayResult;
use Oxhq\Preview\Scenario\ScenarioReplayResult;
use Oxhq\Preview\Scenario\ScenarioRouteResult;
use Oxhq\Preview\Scenario\ScenarioRunner;
use Throwable;

final class ScenarioReplayCommand extends Command
{
    protected $signature = 'preview:scenario:replay
        {scenario : Scenario name}
        {--exact : Replay captured headers and body exactly}
        {--resign : Replay with provider-fresh signed headers}
        {--send-to= : Optional absolute target base URL or full URL to dispatch each replay over HTTP}
        {--json : Emit machine-readable JSON instead of text output}';

    protected $description = 'Replay captures from a local Laravel Preview scenario.';

    public function __construct(
        private readonly ScenarioRunner $runner,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $exact = (bool) $this->option('exact');
        $resign = (bool) $this->option('resign');
        $json = (bool) $this->option('json');
        $scenarioName = (string) $this->argument('scenario');
        $requestedMode = $resign ? 'resign' : ($exact ? 'exact' : null);

        if ($exact === $resign) {
            $message = 'Choose exactly one scenario replay mode: --exact or --resign.';

            if ($json) {
                $this->line($this->encodeJson($this->failurePayload($scenarioName, $requestedMode, $message)));
            } else {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $mode = $resign ? 'resign' : 'exact';

        try {
            $result = $this->runner->replay(
                $scenarioName,
                $mode,
                is_string($this->option('send-to')) ? (string) $this->option('send-to') : null,
            );
        } catch (Throwable $exception) {
            if ($json) {
                $this->line($this->encodeJson($this->failurePayload($scenarioName, $mode, $exception->getMessage())));

                return self::FAILURE;
            }

            $this->error($exception->getMessage());
            $this->error("Scenario replay failed before a result was available for [{$scenarioName}] using [{$mode}].");

            return self::FAILURE;
        }

        $failure = $this->failureFor($result);

        if ($json) {
            $this->line($this->encodeJson($this->jsonPayload($result, $failure)));

            return $failure === null ? self::SUCCESS : self::FAILURE;
        }

        $this->info("Scenario replay ready for [{$result->scenario->name}] using [{$result->mode}].");

        if ($result->seed !== null) {
            $this->line("Seed: {$result->seed}");
        }

        if ($result->captures === []) {
            $this->line('Captures: none');
        }

        foreach ($result->captures as $index => $payload) {
            $this->line(sprintf(
                'Capture: %s %s %s',
                (string) $payload['id'],
                (string) $payload['method'],
                (string) $payload['path'],
            ));

            $dispatch = $result->dispatches[$index] ?? null;

            if ($dispatch instanceof ReplayResult) {
                $this->line("Replay HTTP status: {$dispatch->statusCode}");
                $this->line('Replay dispatch: '.($dispatch->successful() ? 'success' : 'failure'));

                if (! $dispatch->successful()) {
                    $failure ??= $this->dispatchFailure($payload, $dispatch);
                }
            }
        }

        if ($result->routes === []) {
            $this->line('Routes: none');
        }

        foreach ($result->routes as $route) {
            $statusCode = $route->response->getStatusCode();
            $this->line("Route: {$route->preview->name} HTTP {$statusCode}");
            $content = trim((string) $route->response->getContent());

            if ($content !== '') {
                $this->line("Route output: {$content}");
            }

            $routeFailure = $this->routeFailure($result, $route);

            if ($routeFailure !== null) {
                $failure ??= $routeFailure;
            }
        }

        $this->printSummary($result);

        if ($failure !== null) {
            $this->error($failure);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function printSummary(ScenarioReplayResult $result): void
    {
        $this->line($result->summaryLine());
    }

    private function failureFor(ScenarioReplayResult $result): ?string
    {
        foreach ($result->captures as $index => $payload) {
            $dispatch = $result->dispatches[$index] ?? null;

            if ($dispatch instanceof ReplayResult && ! $dispatch->successful()) {
                return $this->dispatchFailure($payload, $dispatch);
            }
        }

        foreach ($result->routes as $route) {
            $failure = $this->routeFailure($result, $route);

            if ($failure !== null) {
                return $failure;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function dispatchFailure(array $payload, ReplayResult $dispatch): string
    {
        return sprintf(
            'Scenario replay failed: dispatch for capture [%s] returned HTTP %d.',
            (string) $payload['id'],
            $dispatch->statusCode,
        );
    }

    private function routeFailure(ScenarioReplayResult $result, ScenarioRouteResult $route): ?string
    {
        $expectation = $this->routeExpectation($result, $route);
        $statusCode = $route->response->getStatusCode();

        if ($expectation !== []) {
            if (array_key_exists('status', $expectation) && $statusCode !== (int) $expectation['status']) {
                return sprintf(
                    'Scenario replay failed: route [%s] expected HTTP %d but returned HTTP %d.',
                    $route->preview->name,
                    (int) $expectation['status'],
                    $statusCode,
                );
            }

            if (array_key_exists('output_contains', $expectation)) {
                $needle = (string) $expectation['output_contains'];
                $output = (string) $route->response->getContent();

                if (! str_contains($output, $needle)) {
                    return sprintf(
                        'Scenario replay failed: route [%s] output did not contain [%s].',
                        $route->preview->name,
                        $needle,
                    );
                }
            }

            return null;
        }

        if (! $route->successful()) {
            return "Scenario replay failed: route [{$route->preview->name}] returned HTTP {$statusCode}.";
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonPayload(ScenarioReplayResult $result, ?string $failure): array
    {
        $captures = [];
        $dispatches = [];

        foreach ($result->captures as $index => $payload) {
            $dispatch = $result->dispatches[$index] ?? null;
            $dispatchPayload = $dispatch instanceof ReplayResult
                ? $this->dispatchPayload($dispatch, (string) $payload['id'])
                : null;

            $captures[] = [
                'id' => (string) $payload['id'],
                'provider' => (string) $payload['provider'],
                'event_type' => isset($payload['event_type']) ? (string) $payload['event_type'] : null,
                'mode' => (string) $payload['mode'],
                'method' => (string) $payload['method'],
                'path' => (string) $payload['path'],
                'query' => is_array($payload['query'] ?? null) ? $payload['query'] : [],
                'captured_at' => (string) $payload['captured_at'],
                'dispatch' => $dispatchPayload,
            ];

            if ($dispatchPayload !== null) {
                $dispatches[] = $dispatchPayload;
            }
        }

        return [
            'scenario' => $result->scenario->name,
            'mode' => $result->mode,
            'seed' => $result->seed,
            'captures' => $captures,
            'dispatches' => $dispatches,
            'routes' => array_map(function (ScenarioRouteResult $route) use ($result): array {
                $expectation = $this->routeExpectation($result, $route);
                $failure = $this->routeFailure($result, $route);

                return [
                    'name' => $route->preview->name,
                    'uri' => $route->preview->uri,
                    'method' => $route->preview->executionMethod,
                    'url' => $route->preview->url,
                    'status_code' => $route->response->getStatusCode(),
                    'output' => trim((string) $route->response->getContent()),
                    'successful' => $failure === null,
                    'expectation' => $expectation === [] ? null : $expectation,
                    'expectation_failure' => $failure,
                ];
            }, $result->routes),
            'summary' => $result->summaryCounts(),
            'successful' => $failure === null,
            'failure' => $failure,
        ];
    }

    /**
     * @return array{status?: int, output_contains?: string}
     */
    private function routeExpectation(ScenarioReplayResult $result, ScenarioRouteResult $route): array
    {
        $expectation = $result->scenario->routeExpectations[$route->preview->name] ?? [];

        return is_array($expectation) ? $expectation : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatchPayload(ReplayResult $dispatch, string $captureId): array
    {
        return [
            'capture_id' => $captureId,
            'status_code' => $dispatch->statusCode,
            'body' => $dispatch->body,
            'headers' => $dispatch->headers,
            'successful' => $dispatch->successful(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failurePayload(string $scenarioName, ?string $mode, string $failure): array
    {
        return [
            'scenario' => $scenarioName,
            'mode' => $mode,
            'seed' => null,
            'captures' => [],
            'dispatches' => [],
            'routes' => [],
            'summary' => [
                'seed' => 0,
                'captures' => 0,
                'dispatches' => 0,
                'routes' => 0,
            ],
            'successful' => false,
            'failure' => $failure,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodeJson(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}

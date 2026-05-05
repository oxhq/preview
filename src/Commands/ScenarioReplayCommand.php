<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use Oxhq\Preview\Capture\ReplayResult;
use Oxhq\Preview\Scenario\ScenarioReplayResult;
use Oxhq\Preview\Scenario\ScenarioRunner;
use Throwable;

final class ScenarioReplayCommand extends Command
{
    protected $signature = 'preview:scenario:replay
        {scenario : Scenario name}
        {--exact : Replay captured headers and body exactly}
        {--resign : Replay with provider-fresh signed headers}
        {--send-to= : Optional absolute target base URL or full URL to dispatch each replay over HTTP}';

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

        if ($exact === $resign) {
            $this->error('Choose exactly one scenario replay mode: --exact or --resign.');

            return self::FAILURE;
        }

        $scenarioName = (string) $this->argument('scenario');
        $mode = $resign ? 'resign' : 'exact';

        try {
            $result = $this->runner->replay(
                $scenarioName,
                $mode,
                is_string($this->option('send-to')) ? (string) $this->option('send-to') : null,
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            $this->error("Scenario replay failed before a result was available for [{$scenarioName}] using [{$mode}].");

            return self::FAILURE;
        }

        $this->info("Scenario replay ready for [{$result->scenario->name}] using [{$result->mode}].");

        if ($result->seed !== null) {
            $this->line("Seed: {$result->seed}");
        }

        if ($result->captures === []) {
            $this->line('Captures: none');
        }

        $failure = null;

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
                    $failure ??= sprintf(
                        'Scenario replay failed: dispatch for capture [%s] returned HTTP %d.',
                        (string) $payload['id'],
                        $dispatch->statusCode,
                    );
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

            if (! $route->successful()) {
                $failure ??= "Scenario replay failed: route [{$route->preview->name}] returned HTTP {$statusCode}.";
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
}

<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use Oxhq\Preview\Scenario\Scenario;
use Oxhq\Preview\Scenario\ScenarioRepository;
use RuntimeException;

final class ScenarioShowCommand extends Command
{
    protected $signature = 'preview:scenario:show {scenario : Scenario name} {--json : Output scenario diagnostics as JSON}';

    protected $description = 'Show a local Laravel Preview scenario.';

    public function __construct(
        private readonly ScenarioRepository $scenarios,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $name = (string) $this->argument('scenario');

        try {
            $scenario = $this->scenarios->find($name);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($scenario === null) {
            $this->error("Scenario [{$name}] was not found.");

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($this->scenarioData($scenario), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line("Scenario: {$scenario->name}");
        $this->line('Seed: '.($scenario->seed ?? 'none'));
        $this->line(sprintf('Routes (%d): %s', count($scenario->routes), $this->formatList($scenario->routes)));
        $this->line(sprintf('Captures (%d): %s', count($scenario->captures), $this->formatList($scenario->captures)));
        $this->line(sprintf('Fakes (%d): %s', count($scenario->fakes), $this->formatList($scenario->fakes)));
        $this->printRouteContext($scenario->routeContext);
        $this->printRouteExpectations($scenario->routeExpectations);

        if ($scenario->notes !== null && trim($scenario->notes) !== '') {
            $this->line('Notes: '.$scenario->notes);
        }

        return self::SUCCESS;
    }

    /**
     * @return array{name: string, seed: ?string, routes: list<string>, routeParameters: array<string, array<string, string>>, routeContext: array<string, array{session?: array<string, string>, guard?: string, user_id?: string, user_model?: class-string|string, readonly_db?: bool, fakes?: list<string>}>, routeExpectations: array<string, array{status: int, output_contains?: string}>, captures: list<string>, fakes: list<string>, notes: ?string}
     */
    private function scenarioData(Scenario $scenario): array
    {
        return [
            'name' => $scenario->name,
            'seed' => $scenario->seed,
            'routes' => $scenario->routes,
            'routeParameters' => $scenario->routeParameters,
            'routeContext' => $scenario->routeContext,
            'routeExpectations' => $scenario->routeExpectations,
            'captures' => $scenario->captures,
            'fakes' => $scenario->fakes,
            'notes' => $scenario->notes,
        ];
    }

    /**
     * @param list<string> $values
     */
    private function formatList(array $values): string
    {
        if ($values === []) {
            return 'none';
        }

        return implode(', ', $values);
    }

    /**
     * @param array<string, array{session?: array<string, string>, guard?: string, user_id?: string, user_model?: class-string|string, readonly_db?: bool, fakes?: list<string>}> $routeContext
     */
    private function printRouteContext(array $routeContext): void
    {
        $routeContext = array_filter($routeContext);

        if ($routeContext === []) {
            return;
        }

        ksort($routeContext);

        $this->line(sprintf('Route context (%d):', count($routeContext)));

        foreach ($routeContext as $route => $context) {
            $this->line(sprintf(
                ' - %s: session keys (%d): %s; guard: %s; user: %s; readonly-db: %s; fakes: %s',
                $route,
                count($context['session'] ?? []),
                $this->formatSessionKeys($context['session'] ?? []),
                $context['guard'] ?? 'none',
                $this->formatUser($context),
                ($context['readonly_db'] ?? false) ? 'requested' : 'not requested',
                $this->formatList($this->sortedList($context['fakes'] ?? [])),
            ));
        }
    }

    /**
     * @param array<string, array{status: int, output_contains?: string}> $routeExpectations
     */
    private function printRouteExpectations(array $routeExpectations): void
    {
        $routeExpectations = array_filter($routeExpectations);

        if ($routeExpectations === []) {
            return;
        }

        ksort($routeExpectations);

        $this->line(sprintf('Route expectations (%d):', count($routeExpectations)));

        foreach ($routeExpectations as $route => $expectation) {
            $this->line(sprintf(
                ' - %s: status %d; output contains: %s',
                $route,
                $expectation['status'],
                $expectation['output_contains'] ?? 'none',
            ));
        }
    }

    /**
     * @param array<string, string> $session
     */
    private function formatSessionKeys(array $session): string
    {
        if ($session === []) {
            return 'none';
        }

        $keys = array_keys($session);
        sort($keys);

        return implode(', ', $keys);
    }

    /**
     * @param array{user_id?: string, user_model?: class-string|string} $context
     */
    private function formatUser(array $context): string
    {
        if (! isset($context['user_id'], $context['user_model'])) {
            return 'none';
        }

        return "{$context['user_id']} via {$context['user_model']}";
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sortedList(array $values): array
    {
        sort($values);

        return $values;
    }
}

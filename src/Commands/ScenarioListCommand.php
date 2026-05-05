<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use Oxhq\Preview\Scenario\Scenario;
use Oxhq\Preview\Scenario\ScenarioRepository;
use RuntimeException;

final class ScenarioListCommand extends Command
{
    protected $signature = 'preview:scenario:list {--json : Output scenario diagnostics as JSON}';

    protected $description = 'List local Laravel Preview scenarios.';

    public function __construct(
        private readonly ScenarioRepository $scenarios,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $scenarios = $this->scenarios->all();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode(
                array_map($this->scenarioRow(...), $scenarios),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        }

        if ($scenarios === []) {
            $this->line('No preview scenarios found.');
            $this->line('Scenario path: '.$this->scenarios->path());

            return self::SUCCESS;
        }

        $this->line('Preview scenarios:');

        foreach ($scenarios as $scenario) {
            $this->line(sprintf(
                ' - %s (captures: %d, routes: %d, route-contexts: %d, fakes: %d)',
                $scenario->name,
                count($scenario->captures),
                count($scenario->routes),
                count(array_filter($scenario->routeContext)),
                count($scenario->fakes),
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @return array{name: string, seed: ?string, route_count: int, capture_count: int, fake_count: int, route_context_count: int, route_expectation_count: int, notes: ?string}
     */
    private function scenarioRow(Scenario $scenario): array
    {
        return [
            'name' => $scenario->name,
            'seed' => $scenario->seed,
            'route_count' => count($scenario->routes),
            'capture_count' => count($scenario->captures),
            'fake_count' => count($scenario->fakes),
            'route_context_count' => count(array_filter($scenario->routeContext)),
            'route_expectation_count' => count(array_filter($scenario->routeExpectations)),
            'notes' => $scenario->notes,
        ];
    }
}

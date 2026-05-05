<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use Oxhq\Preview\Scenario\Scenario;
use Oxhq\Preview\Scenario\ScenarioRepository;
use Throwable;

final class ScenarioStatsCommand extends Command
{
    protected $signature = 'preview:scenario:stats {--json : Output scenario inventory as JSON}';

    protected $description = 'Summarize local Preview scenario inventory.';

    public function __construct(
        private readonly ScenarioRepository $scenarios,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $stats = $this->stats($this->scenarios->all());
        } catch (Throwable $exception) {
            $stats = $this->invalidStats($exception->getMessage());
        }

        if ((bool) $this->option('json')) {
            $this->line($this->json($stats));

            return self::SUCCESS;
        }

        if ($stats['valid'] === false) {
            $this->line('Scenario inventory is invalid.');
            $this->line('Scenario path: '.$stats['scenario_path']);
            $this->line('Issue: '.$stats['issue']);
            $this->line('Total scenarios: 0');

            return self::SUCCESS;
        }

        $this->line('Scenario inventory:');
        $this->line('Scenario path: '.$stats['scenario_path']);
        $this->line('Total scenarios: '.$stats['total']);
        $this->line('With seed: '.$stats['with_seed']);
        $this->line('With routes: '.$stats['with_routes']);
        $this->line('With captures: '.$stats['with_captures']);
        $this->line('With fakes: '.$stats['with_fakes']);
        $this->line('Total routes: '.$stats['route_count']);
        $this->line('Total captures: '.$stats['capture_count']);

        $this->table(['Fake', 'Scenarios'], $this->rows($stats['fake_counts']));
        $this->table(['Scenario', 'Seed', 'Routes', 'Captures', 'Fakes', 'Notes'], array_map(
            fn (array $scenario): array => [
                $scenario['name'],
                $scenario['seed'] ?? 'none',
                $scenario['route_count'],
                $scenario['capture_count'],
                $scenario['fake_count'],
                $scenario['has_notes'] ? 'yes' : 'no',
            ],
            $stats['scenarios'],
        ));

        return self::SUCCESS;
    }

    /**
     * @param list<Scenario> $scenarios
     * @return array{
     *     valid: true,
     *     scenario_path: string,
     *     total: int,
     *     with_seed: int,
     *     with_routes: int,
     *     with_captures: int,
     *     with_fakes: int,
     *     route_count: int,
     *     capture_count: int,
     *     fake_counts: array<string, int>,
     *     scenarios: list<array{name: string, seed: ?string, route_count: int, capture_count: int, fake_count: int, has_notes: bool}>
     * }
     */
    private function stats(array $scenarios): array
    {
        $withSeed = 0;
        $withRoutes = 0;
        $withCaptures = 0;
        $withFakes = 0;
        $routeCount = 0;
        $captureCount = 0;
        $fakeCounts = [];
        $scenarioRows = [];

        foreach ($scenarios as $scenario) {
            $scenarioRouteCount = count($scenario->routes);
            $scenarioCaptureCount = count($scenario->captures);
            $scenarioFakes = $this->scenarioFakes($scenario);

            if ($scenario->seed !== null) {
                $withSeed++;
            }

            if ($scenarioRouteCount > 0) {
                $withRoutes++;
            }

            if ($scenarioCaptureCount > 0) {
                $withCaptures++;
            }

            if ($scenarioFakes !== []) {
                $withFakes++;
            }

            $routeCount += $scenarioRouteCount;
            $captureCount += $scenarioCaptureCount;

            foreach ($scenarioFakes as $fake) {
                $fakeCounts[$fake] = ($fakeCounts[$fake] ?? 0) + 1;
            }

            $scenarioRows[] = [
                'name' => $scenario->name,
                'seed' => $scenario->seed,
                'route_count' => $scenarioRouteCount,
                'capture_count' => $scenarioCaptureCount,
                'fake_count' => count($scenarioFakes),
                'has_notes' => $scenario->notes !== null,
            ];
        }

        ksort($fakeCounts);

        return [
            'valid' => true,
            'scenario_path' => (string) config('preview.scenario_path'),
            'total' => count($scenarios),
            'with_seed' => $withSeed,
            'with_routes' => $withRoutes,
            'with_captures' => $withCaptures,
            'with_fakes' => $withFakes,
            'route_count' => $routeCount,
            'capture_count' => $captureCount,
            'fake_counts' => $fakeCounts,
            'scenarios' => $scenarioRows,
        ];
    }

    /**
     * @return array{valid: false, scenario_path: string, total: 0, issue: string}
     */
    private function invalidStats(string $issue): array
    {
        return [
            'valid' => false,
            'scenario_path' => (string) config('preview.scenario_path'),
            'total' => 0,
            'issue' => $issue,
        ];
    }

    /**
     * @return list<string>
     */
    private function scenarioFakes(Scenario $scenario): array
    {
        $fakes = $scenario->fakes;

        foreach ($scenario->routeContext as $context) {
            foreach ($context['fakes'] ?? [] as $fake) {
                $fakes[] = $fake;
            }
        }

        $fakes = array_values(array_unique($fakes));
        sort($fakes);

        return $fakes;
    }

    /**
     * @param array<string, int> $counts
     * @return list<array{0: string, 1: int}>
     */
    private function rows(array $counts): array
    {
        if ($counts === []) {
            return [['none', 0]];
        }

        return array_map(
            fn (string $name, int $count): array => [$name, $count],
            array_keys($counts),
            array_values($counts),
        );
    }

    /**
     * @param array<string, mixed> $value
     */
    private function json(array $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}

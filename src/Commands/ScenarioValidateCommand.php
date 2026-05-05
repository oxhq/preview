<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use Oxhq\Preview\Capture\CaptureRepository;
use Oxhq\Preview\Route\RoutePreviewService;
use Oxhq\Preview\Scenario\Scenario;
use Oxhq\Preview\Scenario\ScenarioRepository;
use RuntimeException;

final class ScenarioValidateCommand extends Command
{
    protected $signature = 'preview:scenario:validate
        {scenario : Scenario name}
        {--json : Emit machine-readable JSON instead of text output}';

    protected $description = 'Validate local Laravel Preview scenario references without replaying traffic.';

    public function __construct(
        private readonly ScenarioRepository $scenarios,
        private readonly CaptureRepository $captures,
        private readonly RoutePreviewService $routes,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $name = (string) $this->argument('scenario');
        $json = (bool) $this->option('json');

        try {
            $scenario = $this->scenarios->find($name);
        } catch (RuntimeException $exception) {
            return $this->finish($name, [$exception->getMessage()], [], $json);
        }

        if ($scenario === null) {
            return $this->finish($name, ["Scenario [{$name}] was not found."], [], $json);
        }

        [$errors, $warnings, $ok] = $this->validate($scenario);

        if ($json) {
            return $this->finish($scenario->name, $errors, $warnings, true);
        }

        $this->line("Scenario validation: {$scenario->name}");

        foreach ($ok as $line) {
            $this->line("OK {$line}");
        }

        foreach ($warnings as $warning) {
            $this->warn("WARN {$warning}");
        }

        foreach ($errors as $error) {
            $this->error("FAIL {$error}");
        }

        $this->line($errors === [] ? 'Scenario valid.' : 'Scenario invalid.');

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{0: list<string>, 1: list<string>, 2: list<string>}
     */
    private function validate(Scenario $scenario): array
    {
        $errors = [];
        $warnings = [];
        $ok = [];

        if ($scenario->seed === null) {
            $ok[] = 'seed: none';
        } elseif (class_exists($scenario->seed)) {
            $ok[] = "seed: {$scenario->seed}";
        } else {
            $errors[] = "Scenario seed [{$scenario->seed}] was not found.";
        }

        if ($scenario->captures === []) {
            $ok[] = 'captures: none';
        }

        foreach ($scenario->captures as $captureId) {
            try {
                $this->captures->find($captureId);
                $ok[] = "capture: {$captureId}";
            } catch (RuntimeException $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        if ($scenario->routes === []) {
            $ok[] = 'routes: none';
        }

        foreach ($scenario->routes as $routeName) {
            $context = $this->routeContext($scenario, $routeName);

            try {
                $preview = $this->routes->preview(
                    routeName: $routeName,
                    parameters: $this->routeParameters($scenario, $routeName),
                    readonlyDb: (bool) ($context['readonly_db'] ?? false),
                    guard: $context['guard'] ?? null,
                    session: $context['session'] ?? [],
                    userId: $context['user_id'] ?? null,
                    userModel: $context['user_model'] ?? null,
                    fakes: $this->routeFakes($scenario, $routeName),
                );

                $ok[] = "route: {$routeName}";
                array_push($warnings, ...$preview->warnings);
            } catch (RuntimeException $exception) {
                $errors[] = str_replace('Pass allowWrite=true', 'Pass --allow-write', $exception->getMessage());
            }
        }

        if ($scenario->routeExpectations === []) {
            $ok[] = 'route expectations: none';
        }

        foreach (array_keys($scenario->routeExpectations) as $routeName) {
            if (! in_array($routeName, $scenario->routes, true)) {
                $errors[] = "Route expectation [{$routeName}] does not reference a route in scenario [{$scenario->name}].";

                continue;
            }

            $ok[] = "route expectation: {$routeName}";
        }

        return [
            array_values(array_unique($errors)),
            array_values(array_unique($warnings)),
            $ok,
        ];
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

    /**
     * @return array{session?: array<string, string>, guard?: string, user_id?: string, user_model?: class-string|string, readonly_db?: bool, fakes?: list<string>}
     */
    private function routeContext(Scenario $scenario, string $routeName): array
    {
        $context = $scenario->routeContext[$routeName] ?? [];

        return is_array($context) ? $context : [];
    }

    /**
     * @return list<string>
     */
    private function routeFakes(Scenario $scenario, string $routeName): array
    {
        $context = $this->routeContext($scenario, $routeName);

        return array_values(array_unique(array_merge($scenario->fakes, $context['fakes'] ?? [])));
    }

    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    private function finish(string $scenario, array $errors, array $warnings, bool $json): int
    {
        if ($json) {
            $this->line(json_encode([
                'scenario' => $scenario,
                'valid' => $errors === [],
                'errors' => $errors,
                'warnings' => $warnings,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            foreach ($errors as $error) {
                $this->error($error);
            }
        }

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }
}

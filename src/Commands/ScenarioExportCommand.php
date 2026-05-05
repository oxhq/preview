<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use Oxhq\Preview\Scenario\Scenario;
use Oxhq\Preview\Scenario\ScenarioRepository;
use RuntimeException;
use Throwable;

final class ScenarioExportCommand extends Command
{
    protected $signature = 'preview:scenario:export
        {scenario : Scenario name}
        {--path= : Directory to write export into}
        {--json : Output export details as JSON}';

    protected $description = 'Export safe Preview scenario metadata as JSON.';

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

            if ($scenario === null) {
                throw new RuntimeException("Scenario [{$name}] was not found.");
            }

            $root = $this->exportRoot();
            $exportPath = $this->exportPath($root, $scenario->name);

            $this->ensureDirectory($exportPath, $root);
            $this->writeJson(
                $exportPath.DIRECTORY_SEPARATOR.'scenario.json',
                $this->scenarioData($scenario),
                "Scenario [{$scenario->name}] export could not be encoded.",
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line($this->json([
                'scenario' => $scenario->name,
                'export_path' => $exportPath,
                'files' => ['scenario.json'],
            ]));

            return self::SUCCESS;
        }

        $this->info("Exported scenario [{$scenario->name}].");
        $this->line($exportPath.DIRECTORY_SEPARATOR.'scenario.json');

        return self::SUCCESS;
    }

    private function exportRoot(): string
    {
        $path = $this->option('path');

        if (is_string($path) && $path !== '') {
            return $this->resolveDirectory($path);
        }

        $configured = config('preview.export_path');

        if (is_string($configured) && $configured !== '') {
            return $this->resolveDirectory($configured);
        }

        if (function_exists('storage_path')) {
            return $this->resolveDirectory(storage_path('preview/exports/scenarios'));
        }

        return $this->resolveDirectory(getcwd().DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'preview'.DIRECTORY_SEPARATOR.'exports'.DIRECTORY_SEPARATOR.'scenarios');
    }

    private function exportPath(string $root, string $scenarioName): string
    {
        $path = $root.DIRECTORY_SEPARATOR.$this->safeSegment($scenarioName);
        $parent = realpath(dirname($path));

        if ($parent === false) {
            throw new RuntimeException("Export root [{$root}] could not be resolved.");
        }

        $candidate = $parent.DIRECTORY_SEPARATOR.basename($path);
        $this->assertInsideRoot($candidate, $root, "Scenario [{$scenarioName}] export path");

        return $candidate;
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

    private function resolveDirectory(string $path): string
    {
        if (! is_dir($path) && ! mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new RuntimeException("Directory [{$path}] could not be created.");
        }

        $resolved = realpath($path);

        if ($resolved === false || ! is_dir($resolved)) {
            throw new RuntimeException("Directory [{$path}] could not be resolved.");
        }

        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    private function ensureDirectory(string $path, string $root): void
    {
        if (! is_dir($path) && ! mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new RuntimeException("Directory [{$path}] could not be created.");
        }

        $resolved = realpath($path);

        if ($resolved === false || ! is_dir($resolved)) {
            throw new RuntimeException("Directory [{$path}] could not be resolved.");
        }

        $this->assertInsideRoot($resolved, $root, "Directory [{$path}]");
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(string $path, array $data, string $errorMessage): void
    {
        try {
            $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException($errorMessage);
        }

        if (file_put_contents($path, $encoded.PHP_EOL) === false) {
            throw new RuntimeException("File [{$path}] could not be written.");
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    private function json(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function safeSegment(string $value): string
    {
        $segment = preg_replace('/[^A-Za-z0-9_.-]/', '-', $value) ?: 'scenario';

        return in_array($segment, ['.', '..'], true) ? 'scenario' : $segment;
    }

    private function assertInsideRoot(string $path, string $root, string $label): void
    {
        $path = $this->normalizePath($path);
        $root = rtrim($this->normalizePath($root), DIRECTORY_SEPARATOR);

        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
            $root = strtolower($root);
        }

        if ($path !== $root && ! str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("{$label} is outside the selected export root.");
        }
    }

    private function normalizePath(string $path): string
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }
}

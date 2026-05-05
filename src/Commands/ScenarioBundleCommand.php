<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use JsonException;
use Oxhq\Preview\Capture\CaptureRecord;
use Oxhq\Preview\Capture\CaptureRepository;
use Oxhq\Preview\Scenario\Scenario;
use Oxhq\Preview\Scenario\ScenarioRepository;
use RuntimeException;
use Throwable;

final class ScenarioBundleCommand extends Command
{
    protected $signature = 'preview:scenario:bundle
        {scenario : Scenario name}
        {--path= : Directory to write bundle into}
        {--json : Output bundle details as JSON}';

    protected $description = 'Bundle safe Preview scenario metadata and referenced capture summaries.';

    public function __construct(
        private readonly ScenarioRepository $scenarios,
        private readonly CaptureRepository $captures,
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

            $root = $this->bundleRoot();
            $bundlePath = $this->bundlePath($root, $scenario->name);

            $this->ensureDirectory($bundlePath, $root);
            $this->writeJson(
                $bundlePath.DIRECTORY_SEPARATOR.'bundle.json',
                $this->bundleData($scenario),
                "Scenario [{$scenario->name}] bundle could not be encoded.",
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line($this->json([
                'scenario' => $scenario->name,
                'bundle_path' => $bundlePath,
                'files' => ['bundle.json'],
                'raw_included' => false,
            ]));

            return self::SUCCESS;
        }

        $this->info("Bundled scenario [{$scenario->name}].");
        $this->line($bundlePath.DIRECTORY_SEPARATOR.'bundle.json');
        $this->line('Raw payloads and header values were not written.');

        return self::SUCCESS;
    }

    private function bundleRoot(): string
    {
        $path = $this->option('path');

        if (is_string($path) && $path !== '') {
            return $this->resolveDirectory($path);
        }

        $configured = config('preview.export_path');

        if (is_string($configured) && $configured !== '') {
            return $this->resolveDirectory($configured.DIRECTORY_SEPARATOR.'scenario-bundles');
        }

        if (function_exists('storage_path')) {
            return $this->resolveDirectory(storage_path('preview/exports/scenario-bundles'));
        }

        return $this->resolveDirectory(getcwd().DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'preview'.DIRECTORY_SEPARATOR.'exports'.DIRECTORY_SEPARATOR.'scenario-bundles');
    }

    private function bundlePath(string $root, string $scenarioName): string
    {
        $path = $root.DIRECTORY_SEPARATOR.$this->safeSegment($scenarioName);
        $parent = realpath(dirname($path));

        if ($parent === false) {
            throw new RuntimeException("Bundle root [{$root}] could not be resolved.");
        }

        $candidate = $parent.DIRECTORY_SEPARATOR.basename($path);
        $this->assertInsideRoot($candidate, $root, "Scenario [{$scenarioName}] bundle path");

        return $candidate;
    }

    /**
     * @return array<string, mixed>
     */
    private function bundleData(Scenario $scenario): array
    {
        return [
            'name' => $scenario->name,
            'seed' => $scenario->seed,
            'routes' => $scenario->routes,
            'routeParameters' => $scenario->routeParameters,
            'routeContext' => $scenario->routeContext,
            'routeExpectations' => $scenario->routeExpectations,
            'route_summaries' => $this->routeSummaries($scenario),
            'captures' => $scenario->captures,
            'capture_summaries' => $this->captureSummaries($scenario),
            'missing_captures' => $this->missingCaptures($scenario),
            'fakes' => $scenario->fakes,
            'notes' => $scenario->notes,
        ];
    }

    /**
     * @return list<array{name: string, expectation: array{status: int, output_contains?: string}|null}>
     */
    private function routeSummaries(Scenario $scenario): array
    {
        return array_map(
            fn (string $routeName): array => [
                'name' => $routeName,
                'expectation' => $scenario->routeExpectations[$routeName] ?? null,
            ],
            $scenario->routes,
        );
    }

    /**
     * @return list<array{id: string, provider: string, event: string|null, method: string, path: string, verified: bool, body_sha256: string|null, body_bytes: int|null}>
     */
    private function captureSummaries(Scenario $scenario): array
    {
        $summaries = [];

        foreach ($scenario->captures as $captureId) {
            try {
                $summaries[] = $this->captureSummary($this->captures->find($captureId));
            } catch (RuntimeException) {
                continue;
            }
        }

        return $summaries;
    }

    /**
     * @return list<string>
     */
    private function missingCaptures(Scenario $scenario): array
    {
        $missing = [];

        foreach ($scenario->captures as $captureId) {
            try {
                $this->captures->find($captureId);
            } catch (RuntimeException) {
                $missing[] = $captureId;
            }
        }

        return $missing;
    }

    /**
     * @return array{id: string, provider: string, event: string|null, method: string, path: string, verified: bool, body_sha256: string|null, body_bytes: int|null}
     */
    private function captureSummary(CaptureRecord $record): array
    {
        return [
            'id' => $record->id,
            'provider' => $record->provider,
            'event' => $record->eventType,
            'method' => $record->method,
            'path' => $record->path,
            'verified' => $record->verified,
            'body_sha256' => $this->bodySha256($record),
            'body_bytes' => $this->bodyBytes($record),
        ];
    }

    private function bodySha256(CaptureRecord $record): ?string
    {
        $hash = is_file($record->rawBodyPath) && is_readable($record->rawBodyPath)
            ? hash_file('sha256', $record->rawBodyPath)
            : false;

        return is_string($hash) ? $hash : null;
    }

    private function bodyBytes(CaptureRecord $record): ?int
    {
        $bytes = is_file($record->rawBodyPath) && is_readable($record->rawBodyPath)
            ? filesize($record->rawBodyPath)
            : false;

        return is_int($bytes) ? $bytes : null;
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
        } catch (JsonException) {
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
            throw new RuntimeException("{$label} is outside the selected bundle root.");
        }
    }

    private function normalizePath(string $path): string
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }
}

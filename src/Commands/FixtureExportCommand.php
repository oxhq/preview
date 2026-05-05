<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

final class FixtureExportCommand extends Command
{
    protected $signature = 'preview:fixture:export
        {capture_id : Capture ID recorded in a fixture manifest}
        {--path= : Directory to write export into}
        {--json : Output export details as JSON}';

    protected $description = 'Export a generated Preview fixture without copying local-only payloads.';

    public function handle(): int
    {
        $captureId = (string) $this->argument('capture_id');

        try {
            $manifestPath = $this->findManifest($captureId);
            $manifest = $this->readManifest($manifestPath);
            $root = $this->exportRoot();
            $exportPath = $this->exportPath($root, $captureId);
            $payloadCopied = false;
            $files = ['manifest.json', 'fixture.php', 'headers.php'];

            $this->ensureDirectory($exportPath, $root);
            $this->copyRequired(dirname($manifestPath).DIRECTORY_SEPARATOR.'manifest.json', $exportPath.DIRECTORY_SEPARATOR.'manifest.json');
            $this->copyRequired(dirname($manifestPath).DIRECTORY_SEPARATOR.'fixture.php', $exportPath.DIRECTORY_SEPARATOR.'fixture.php');
            $this->copyRequired(dirname($manifestPath).DIRECTORY_SEPARATOR.'headers.php', $exportPath.DIRECTORY_SEPARATOR.'headers.php');

            if (! $this->payloadLocalOnly($manifest)) {
                $this->copyRequired(dirname($manifestPath).DIRECTORY_SEPARATOR.'payload.json', $exportPath.DIRECTORY_SEPARATOR.'payload.json');
                $files[] = 'payload.json';
                $payloadCopied = true;
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line($this->json([
                'capture_id' => $captureId,
                'export_path' => $exportPath,
                'files' => $files,
                'payload_copied' => $payloadCopied,
            ]));

            return self::SUCCESS;
        }

        $this->info("Exported fixture [{$captureId}].");
        $this->line($exportPath);
        $this->line('Payload copied: '.($payloadCopied ? 'yes' : 'no'));

        return self::SUCCESS;
    }

    private function findManifest(string $captureId): string
    {
        foreach ($this->manifestPaths($this->fixtureRoot()) as $path) {
            $manifest = $this->readManifest($path);

            if (($manifest['capture_id'] ?? null) === $captureId) {
                return $path;
            }
        }

        throw new RuntimeException("Fixture manifest for capture [{$captureId}] was not found.");
    }

    /**
     * @return list<string>
     */
    private function manifestPaths(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $paths = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->isFile() && $file->getFilename() === 'manifest.json') {
                $paths[] = $file->getPathname();
            }
        }

        sort($paths);

        return $paths;
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifest(string $path): array
    {
        $contents = file_get_contents($path);
        $manifest = $contents === false ? null : json_decode($contents, true);

        if (! is_array($manifest)) {
            throw new RuntimeException("Fixture manifest [{$path}] could not be read.");
        }

        return $manifest;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function payloadLocalOnly(array $manifest): bool
    {
        return (bool) ($manifest['payload']['local_only'] ?? false);
    }

    private function fixtureRoot(): string
    {
        $configured = config('preview.fixture_path');

        return is_string($configured) && $configured !== ''
            ? rtrim($configured, DIRECTORY_SEPARATOR)
            : getcwd().DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'Preview';
    }

    private function exportRoot(): string
    {
        $path = $this->option('path');

        if (is_string($path) && $path !== '') {
            return $this->resolveDirectory($path);
        }

        $configured = config('preview.export_path');

        if (is_string($configured) && $configured !== '') {
            return $this->resolveDirectory($configured.DIRECTORY_SEPARATOR.'fixtures');
        }

        return $this->resolveDirectory(storage_path('preview/exports/fixtures'));
    }

    private function exportPath(string $root, string $captureId): string
    {
        $path = $root.DIRECTORY_SEPARATOR.$this->safeSegment($captureId);
        $parent = realpath(dirname($path));

        if ($parent === false) {
            throw new RuntimeException("Export root [{$root}] could not be resolved.");
        }

        $candidate = $parent.DIRECTORY_SEPARATOR.basename($path);
        $this->assertInsideRoot($candidate, $root, "Fixture [{$captureId}] export path");

        return $candidate;
    }

    private function copyRequired(string $source, string $destination): void
    {
        if (! is_file($source) || ! is_readable($source)) {
            throw new RuntimeException("Fixture file [{$source}] could not be read.");
        }

        if (! copy($source, $destination)) {
            throw new RuntimeException("Fixture file [{$destination}] could not be written.");
        }
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

    private function safeSegment(string $value): string
    {
        $segment = preg_replace('/[^A-Za-z0-9_.-]/', '-', $value) ?: 'fixture';

        return in_array($segment, ['.', '..'], true) ? 'fixture' : $segment;
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

    /**
     * @param array<string, mixed> $value
     */
    private function json(array $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function normalizePath(string $path): string
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }
}

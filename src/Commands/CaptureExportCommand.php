<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use Oxhq\Preview\Capture\CaptureRecord;
use Oxhq\Preview\Capture\CaptureRepository;
use Oxhq\Preview\Core\GitIgnoreGuard;
use RuntimeException;
use Throwable;

final class CaptureExportCommand extends Command
{
    protected $signature = 'preview:capture:export
        {capture : Capture ID}
        {--path= : Directory to write export into}
        {--json : Output export details as JSON}';

    protected $description = 'Export safe redacted Preview capture metadata.';

    public function __construct(
        private readonly CaptureRepository $captures,
        private readonly GitIgnoreGuard $gitIgnoreGuard,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $record = $this->captures->find((string) $this->argument('capture'));
            $root = $this->exportRoot();
            $this->gitIgnoreGuard->ensureIgnored($root);
            $exportPath = $this->exportPath($root, $record->id);

            $this->ensureDirectory($exportPath, $root);
            $this->writeJson(
                $exportPath.DIRECTORY_SEPARATOR.'metadata.json',
                $this->metadata($record),
                "Capture [{$record->id}] export metadata could not be encoded.",
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line($this->json([
                'capture_id' => $record->id,
                'export_path' => $exportPath,
                'files' => ['metadata.json'],
                'raw_included' => false,
            ]));

            return self::SUCCESS;
        }

        $this->info("Exported capture [{$record->id}].");
        $this->line($exportPath);
        $this->line('Raw payload and raw headers were not exported.');

        return self::SUCCESS;
    }

    private function exportRoot(): string
    {
        $path = $this->option('path');

        if (is_string($path) && $path !== '') {
            return $this->resolveDirectory($path);
        }

        if (function_exists('storage_path')) {
            return $this->resolveDirectory(storage_path('preview/exports'));
        }

        return $this->resolveDirectory(getcwd().DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'preview'.DIRECTORY_SEPARATOR.'exports');
    }

    private function exportPath(string $root, string $captureId): string
    {
        $path = $root.DIRECTORY_SEPARATOR.$this->safeSegment($captureId);
        $parent = realpath(dirname($path));

        if ($parent === false) {
            throw new RuntimeException("Export root [{$root}] could not be resolved.");
        }

        $candidate = $parent.DIRECTORY_SEPARATOR.basename($path);
        $this->assertInsideRoot($candidate, $root, "Capture [{$captureId}] export path");

        return $candidate;
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(CaptureRecord $record): array
    {
        return [
            'id' => $record->id,
            'provider' => $record->provider,
            'event_type' => $record->eventType,
            'method' => $record->method,
            'path' => $record->path,
            'query' => $record->query,
            'headers' => $record->headers,
            'captured_at' => $record->capturedAt->format(DATE_ATOM),
            'verified' => $record->verified,
            'verification_message' => $record->verificationMessage,
            'metadata' => $record->metadata,
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
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
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
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function safeSegment(string $value): string
    {
        $segment = preg_replace('/[^A-Za-z0-9_.-]/', '-', $value) ?: 'capture';

        return in_array($segment, ['.', '..'], true) ? 'capture' : $segment;
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

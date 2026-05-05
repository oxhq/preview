<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use Oxhq\Preview\Capture\CaptureRecord;
use Oxhq\Preview\Capture\CaptureRepository;
use Oxhq\Preview\Core\GitIgnoreGuard;
use RuntimeException;
use Throwable;

final class CaptureBundleCommand extends Command
{
    protected $signature = 'preview:capture:bundle
        {capture : Capture ID}
        {--path= : Directory to write bundle into}
        {--include-raw : Include raw body and raw headers in the bundle}
        {--json : Output bundle details as JSON}';

    protected $description = 'Bundle safe Preview capture metadata and optional raw files.';

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
            $root = $this->bundleRoot();
            $this->gitIgnoreGuard->ensureIgnored($root);
            $bundlePath = $this->bundlePath($root, $record->id);
            $includeRaw = (bool) $this->option('include-raw');
            $files = ['metadata.json'];

            $this->ensureDirectory($bundlePath, $root);

            if ($includeRaw) {
                $this->copyFile($record->rawBodyPath, $bundlePath.DIRECTORY_SEPARATOR.'body.raw');
                $files[] = 'body.raw';

                if ($record->rawHeadersPath !== null) {
                    $this->copyFile($record->rawHeadersPath, $bundlePath.DIRECTORY_SEPARATOR.'headers.raw.json');
                    $files[] = 'headers.raw.json';
                }
            }

            $this->writeJson(
                $bundlePath.DIRECTORY_SEPARATOR.'metadata.json',
                $this->metadata($record, $includeRaw, $files),
                "Capture [{$record->id}] bundle metadata could not be encoded.",
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line($this->json([
                'capture_id' => $record->id,
                'bundle_path' => $bundlePath,
                'files' => $files,
                'raw_included' => $includeRaw,
            ]));

            return self::SUCCESS;
        }

        $this->info("Bundled capture [{$record->id}].");
        $this->line($bundlePath);
        $this->line($includeRaw ? 'Raw payload and raw headers were included.' : 'Raw payload and raw headers were not included.');

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
            return $this->resolveDirectory($configured);
        }

        if (function_exists('storage_path')) {
            return $this->resolveDirectory(storage_path('preview/exports'));
        }

        return $this->resolveDirectory(getcwd().DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'preview'.DIRECTORY_SEPARATOR.'exports');
    }

    private function bundlePath(string $root, string $captureId): string
    {
        $path = $root.DIRECTORY_SEPARATOR.$this->safeSegment($captureId);
        $parent = realpath(dirname($path));

        if ($parent === false) {
            throw new RuntimeException("Bundle root [{$root}] could not be resolved.");
        }

        $candidate = $parent.DIRECTORY_SEPARATOR.basename($path);
        $this->assertInsideRoot($candidate, $root, "Capture [{$captureId}] bundle path");

        return $candidate;
    }

    /**
     * @param list<string> $files
     * @return array<string, mixed>
     */
    private function metadata(CaptureRecord $record, bool $rawIncluded, array $files): array
    {
        return [
            'id' => $record->id,
            'provider' => $record->provider,
            'event_type' => $record->eventType,
            'method' => $record->method,
            'path' => $record->path,
            'query' => $record->query,
            'header_names' => $this->headerNames($record),
            'captured_at' => $record->capturedAt->format(DATE_ATOM),
            'verified' => $record->verified,
            'verification_message' => $record->verificationMessage,
            'raw_body_sha256' => $this->sha256($record->rawBodyPath),
            'raw_body_bytes' => $this->bytes($record->rawBodyPath),
            'raw_headers_sha256' => $this->sha256($record->rawHeadersPath),
            'raw_headers_bytes' => $this->bytes($record->rawHeadersPath),
            'metadata' => $record->metadata,
            'raw_included' => $rawIncluded,
            'files' => $files,
        ];
    }

    /**
     * @return list<string>
     */
    private function headerNames(CaptureRecord $record): array
    {
        $headers = $record->rawHeadersPath === null ? $record->headers : $record->rawHeaders();
        $names = array_map('strval', array_keys($headers));
        sort($names, SORT_STRING);

        return array_values($names);
    }

    private function sha256(?string $path): ?string
    {
        if ($path === null || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $hash = hash_file('sha256', $path);

        return is_string($hash) ? $hash : null;
    }

    private function bytes(?string $path): ?int
    {
        if ($path === null || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $bytes = filesize($path);

        return is_int($bytes) ? $bytes : null;
    }

    private function copyFile(string $source, string $destination): void
    {
        if (! is_file($source) || ! is_readable($source)) {
            throw new RuntimeException("File [{$source}] could not be read.");
        }

        if (! copy($source, $destination)) {
            throw new RuntimeException("File [{$destination}] could not be written.");
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

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(string $path, array $data, string $errorMessage): void
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($encoded === false || file_put_contents($path, $encoded.PHP_EOL) === false) {
            throw new RuntimeException($errorMessage);
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
            throw new RuntimeException("{$label} is outside the selected bundle root.");
        }
    }

    private function normalizePath(string $path): string
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }
}

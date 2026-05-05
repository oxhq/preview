<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class FixtureListCommand extends Command
{
    protected $signature = 'preview:fixture:list
        {--json : Emit machine-readable JSON output}';

    protected $description = 'List generated Preview fixture manifests.';

    public function handle(): int
    {
        $rows = $this->manifestRows($this->fixtureRoot());

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->line('No fixture manifests found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Capture ID', 'Provider', 'Event', 'Endpoint', 'Signing', 'Local Only'],
            array_map(
                fn (array $row): array => [
                    $row['capture_id'],
                    $row['provider'] ?? '-',
                    $row['event_type'] ?? '-',
                    $row['endpoint'] ?? $row['manifest'] ?? '-',
                    $row['signing'] ?? '-',
                    is_bool($row['local_only'] ?? null) ? ($row['local_only'] ? 'yes' : 'no') : '-',
                ],
                $rows,
            ),
        );

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function manifestRows(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $manifestPaths = $this->manifestPaths($root);
        $rows = [];

        foreach ($manifestPaths as $path) {
            $rows[] = $this->manifestRow($path, $root);
        }

        usort($rows, function (array $left, array $right): int {
            if (($left['valid'] ?? false) !== ($right['valid'] ?? false)) {
                return ($left['valid'] ?? false) ? -1 : 1;
            }

            return strcmp((string) ($left['manifest'] ?? $left['capture_id']), (string) ($right['manifest'] ?? $right['capture_id']));
        });

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function manifestPaths(string $root): array
    {
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
    private function manifestRow(string $path, string $root): array
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            return $this->invalidRow($path, $root, 'Manifest could not be read.');
        }

        $manifest = json_decode($contents, true);

        if (! is_array($manifest)) {
            return $this->invalidRow($path, $root, json_last_error_msg());
        }

        if (! $this->isValidManifest($manifest)) {
            return $this->invalidRow($path, $root, 'Manifest is missing required summary fields.');
        }

        return [
            'capture_id' => $manifest['capture_id'],
            'provider' => $manifest['provider'],
            'event_type' => $manifest['event_type'],
            'endpoint' => $manifest['endpoint'],
            'signing' => $manifest['signing'],
            'local_only' => $manifest['payload']['local_only'],
            'valid' => true,
        ];
    }

    /**
     * @param array<mixed> $manifest
     */
    private function isValidManifest(array $manifest): bool
    {
        return isset($manifest['capture_id'], $manifest['provider'], $manifest['endpoint'], $manifest['signing'])
            && array_key_exists('event_type', $manifest)
            && is_string($manifest['capture_id'])
            && $manifest['capture_id'] !== ''
            && is_string($manifest['provider'])
            && $manifest['provider'] !== ''
            && (is_string($manifest['event_type'] ?? null) || ($manifest['event_type'] ?? null) === null)
            && is_string($manifest['endpoint'])
            && is_string($manifest['signing'])
            && isset($manifest['payload'])
            && is_array($manifest['payload'])
            && isset($manifest['payload']['local_only'])
            && is_bool($manifest['payload']['local_only']);
    }

    /**
     * @return array<string, mixed>
     */
    private function invalidRow(string $path, string $root, string $error): array
    {
        return [
            'capture_id' => 'invalid',
            'provider' => null,
            'event_type' => null,
            'endpoint' => null,
            'signing' => null,
            'local_only' => null,
            'valid' => false,
            'manifest' => $this->relativePath($path, $root),
            'error' => $error,
        ];
    }

    private function fixtureRoot(): string
    {
        $configured = config('preview.fixture_path');

        return is_string($configured) && $configured !== ''
            ? rtrim($configured, DIRECTORY_SEPARATOR)
            : getcwd().DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'Preview';
    }

    private function relativePath(string $path, string $root): string
    {
        $normalizedPath = str_replace('\\', '/', $path);
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');

        if (str_starts_with($normalizedPath, $normalizedRoot.'/')) {
            return substr($normalizedPath, strlen($normalizedRoot) + 1);
        }

        return $normalizedPath;
    }
}

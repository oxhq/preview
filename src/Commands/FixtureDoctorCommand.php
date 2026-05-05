<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class FixtureDoctorCommand extends Command
{
    protected $signature = 'preview:fixture:doctor {--json : Output fixture diagnostics as JSON}';

    protected $description = 'Validate Preview fixture manifests without loading fixture payloads.';

    public function handle(): int
    {
        $rows = $this->manifestRows($this->fixtureRoot());

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $issueRows = array_values(array_filter($rows, fn (array $row): bool => $row['issues'] !== []));

        $this->line(sprintf(
            'Fixture manifest diagnostics: %d manifest%s, %d valid, %d with issues.',
            count($rows),
            count($rows) === 1 ? '' : 's',
            count($rows) - count($issueRows),
            count($issueRows),
        ));

        if ($rows === []) {
            $this->line('No fixture manifests found.');

            return self::SUCCESS;
        }

        if ($issueRows === []) {
            $this->line('No fixture manifest issues found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Path', 'Capture ID', 'Issues'],
            array_map(
                fn (array $row): array => [
                    $row['path'],
                    $row['capture_id'] ?? '-',
                    implode('; ', $row['issues']),
                ],
                $issueRows,
            ),
        );

        return self::SUCCESS;
    }

    /**
     * @return list<array{
     *     path: string,
     *     valid: bool,
     *     capture_id: ?string,
     *     provider: ?string,
     *     event_type: ?string,
     *     endpoint: ?string,
     *     signing: ?string,
     *     payload_local_only: ?bool,
     *     issues: list<string>
     * }>
     */
    private function manifestRows(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $rows = [];

        foreach ($this->manifestPaths($root) as $path) {
            $rows[] = $this->manifestRow($path, $root);
        }

        usort(
            $rows,
            fn (array $left, array $right): int => strcmp((string) $left['path'], (string) $right['path']),
        );

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
     * @return array{
     *     path: string,
     *     valid: bool,
     *     capture_id: ?string,
     *     provider: ?string,
     *     event_type: ?string,
     *     endpoint: ?string,
     *     signing: ?string,
     *     payload_local_only: ?bool,
     *     issues: list<string>
     * }
     */
    private function manifestRow(string $path, string $root): array
    {
        $relativePath = $this->relativePath($path, $root);
        $directory = dirname($path);
        $contents = file_get_contents($path);

        if ($contents === false) {
            return $this->row($relativePath, null, ['Manifest could not be read.']);
        }

        $manifest = json_decode($contents, true);

        if (! is_array($manifest)) {
            return $this->row($relativePath, null, ['Manifest JSON is invalid: '.json_last_error_msg().'.']);
        }

        $issues = $this->manifestIssues($manifest);

        if (! file_exists($directory.DIRECTORY_SEPARATOR.'fixture.php')) {
            $issues[] = 'Missing companion fixture.php.';
        }

        if (! file_exists($directory.DIRECTORY_SEPARATOR.'headers.php')) {
            $issues[] = 'Missing companion headers.php.';
        }

        $payloadLocalOnly = $this->payloadLocalOnly($manifest);

        if (is_bool($payloadLocalOnly)) {
            $payloadPath = $this->expectedPayloadPath($directory, $manifest, $payloadLocalOnly);

            if (! file_exists($payloadPath)) {
                $issues[] = 'Missing expected payload.json.';
            }
        }

        return $this->row($relativePath, $manifest, $issues);
    }

    /**
     * @param array<mixed> $manifest
     * @return list<string>
     */
    private function manifestIssues(array $manifest): array
    {
        $issues = [];

        foreach ([
            'capture_id' => 'capture_id',
            'provider' => 'provider',
            'method' => 'method',
            'endpoint' => 'endpoint',
            'signing' => 'signing',
        ] as $key => $label) {
            if (! isset($manifest[$key]) || ! is_string($manifest[$key]) || $manifest[$key] === '') {
                $issues[] = "Missing or invalid required field {$label}.";
            }
        }

        if (! isset($manifest['payload']) || ! is_array($manifest['payload'])) {
            $issues[] = 'Missing or invalid required field payload.';
        } elseif (! array_key_exists('local_only', $manifest['payload']) || ! is_bool($manifest['payload']['local_only'])) {
            $issues[] = 'Missing or invalid required field payload.local_only.';
        }

        return $issues;
    }

    /**
     * @param array<mixed> $manifest
     */
    private function payloadLocalOnly(array $manifest): ?bool
    {
        if (! isset($manifest['payload']) || ! is_array($manifest['payload'])) {
            return null;
        }

        return is_bool($manifest['payload']['local_only'] ?? null) ? $manifest['payload']['local_only'] : null;
    }

    /**
     * @param array<mixed> $manifest
     */
    private function expectedPayloadPath(string $directory, array $manifest, bool $localOnly): string
    {
        if (! $localOnly) {
            return $directory.DIRECTORY_SEPARATOR.'payload.json';
        }

        $provider = basename(dirname($directory));

        return $directory.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'.local'.DIRECTORY_SEPARATOR.$provider.DIRECTORY_SEPARATOR.basename($directory).DIRECTORY_SEPARATOR.'payload.json';
    }

    /**
     * @param array<mixed>|null $manifest
     * @param list<string> $issues
     * @return array{
     *     path: string,
     *     valid: bool,
     *     capture_id: ?string,
     *     provider: ?string,
     *     event_type: ?string,
     *     endpoint: ?string,
     *     signing: ?string,
     *     payload_local_only: ?bool,
     *     issues: list<string>
     * }
     */
    private function row(string $path, ?array $manifest, array $issues): array
    {
        return [
            'path' => $path,
            'valid' => $issues === [],
            'capture_id' => is_string($manifest['capture_id'] ?? null) ? $manifest['capture_id'] : null,
            'provider' => is_string($manifest['provider'] ?? null) ? $manifest['provider'] : null,
            'event_type' => is_string($manifest['event_type'] ?? null) ? $manifest['event_type'] : null,
            'endpoint' => is_string($manifest['endpoint'] ?? null) ? $manifest['endpoint'] : null,
            'signing' => is_string($manifest['signing'] ?? null) ? $manifest['signing'] : null,
            'payload_local_only' => $manifest === null ? null : $this->payloadLocalOnly($manifest),
            'issues' => $issues,
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

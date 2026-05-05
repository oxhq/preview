<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class FixtureStatsCommand extends Command
{
    protected $signature = 'preview:fixture:stats
        {--json : Emit machine-readable JSON output}';

    protected $description = 'Summarize local Preview fixture manifest inventory.';

    public function handle(): int
    {
        $stats = $this->stats($this->fixtureRoot());

        if ((bool) $this->option('json')) {
            $this->line($this->json($stats));

            return self::SUCCESS;
        }

        $this->line('Fixture inventory:');
        $this->line('Fixture path: '.$stats['fixture_path']);
        $this->line('Total manifests: '.$stats['total_manifests']);
        $this->line('Total fixtures: '.$stats['total_fixtures']);
        $this->line('Invalid manifests: '.$stats['invalid_manifest_count']);
        $this->line('Local-only payloads: '.$stats['local_only_payload_count']);
        $this->line('Checked-in payloads: '.$stats['checked_in_payload_count']);

        $this->table(['Provider', 'Fixtures'], $this->rows($stats['providers']));
        $this->table(['Signing Mode', 'Fixtures'], $this->rows($stats['signing_modes']));

        return self::SUCCESS;
    }

    /**
     * @return array{
     *     fixture_path: string,
     *     total_manifests: int,
     *     total_fixtures: int,
     *     invalid_manifest_count: int,
     *     local_only_payload_count: int,
     *     checked_in_payload_count: int,
     *     providers: array<string, int>,
     *     signing_modes: array<string, int>
     * }
     */
    private function stats(string $root): array
    {
        $totalManifests = 0;
        $totalFixtures = 0;
        $invalidManifests = 0;
        $localOnlyPayloads = 0;
        $checkedInPayloads = 0;
        $providers = [];
        $signingModes = [];

        foreach ($this->manifestPaths($root) as $path) {
            $totalManifests++;
            $manifest = $this->manifest($path);

            if ($manifest === null || ! $this->isValidManifest($manifest)) {
                $invalidManifests++;

                continue;
            }

            $totalFixtures++;

            $provider = $manifest['provider'];
            $providers[$provider] = ($providers[$provider] ?? 0) + 1;

            $signing = $manifest['signing'];
            $signingModes[$signing] = ($signingModes[$signing] ?? 0) + 1;

            $manifest['payload']['local_only'] ? $localOnlyPayloads++ : $checkedInPayloads++;
        }

        ksort($providers);
        ksort($signingModes);

        return [
            'fixture_path' => str_replace('\\', '/', $root),
            'total_manifests' => $totalManifests,
            'total_fixtures' => $totalFixtures,
            'invalid_manifest_count' => $invalidManifests,
            'local_only_payload_count' => $localOnlyPayloads,
            'checked_in_payload_count' => $checkedInPayloads,
            'providers' => $providers,
            'signing_modes' => $signingModes,
        ];
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
     * @return array<mixed>|null
     */
    private function manifest(string $path): ?array
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $manifest = json_decode($contents, true);

        return is_array($manifest) ? $manifest : null;
    }

    /**
     * @param array<mixed> $manifest
     */
    private function isValidManifest(array $manifest): bool
    {
        return isset($manifest['capture_id'], $manifest['provider'], $manifest['method'], $manifest['endpoint'], $manifest['signing'])
            && array_key_exists('event_type', $manifest)
            && is_string($manifest['capture_id'])
            && $manifest['capture_id'] !== ''
            && is_string($manifest['provider'])
            && $manifest['provider'] !== ''
            && is_string($manifest['method'])
            && $manifest['method'] !== ''
            && is_string($manifest['endpoint'])
            && $manifest['endpoint'] !== ''
            && is_string($manifest['signing'])
            && $manifest['signing'] !== ''
            && (is_string($manifest['event_type'] ?? null) || ($manifest['event_type'] ?? null) === null)
            && isset($manifest['payload'])
            && is_array($manifest['payload'])
            && array_key_exists('local_only', $manifest['payload'])
            && is_bool($manifest['payload']['local_only']);
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

    private function fixtureRoot(): string
    {
        $configured = config('preview.fixture_path');

        return is_string($configured) && $configured !== ''
            ? rtrim($configured, DIRECTORY_SEPARATOR)
            : getcwd().DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'Preview';
    }
}

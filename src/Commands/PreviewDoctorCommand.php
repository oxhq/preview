<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use Oxhq\Preview\Capture\CaptureRepository;
use Oxhq\Preview\Core\ProviderRegistry;
use Oxhq\Preview\Core\Transport\TransportRegistry;

final class PreviewDoctorCommand extends Command
{
    protected $signature = 'preview:doctor {--json : Emit machine-readable JSON output}';

    protected $description = 'Summarize local Laravel Preview readiness without opening tunnels or replaying traffic.';

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly TransportRegistry $transports,
        private readonly CaptureRepository $captures,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $summary = $this->summary();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('Preview readiness summary:');
        $this->line($this->pathLine('Storage path', $summary['paths']['storage']));
        $this->line($this->pathLine('Fixture path', $summary['paths']['fixtures']));
        $this->line($this->pathLine('Scenario path', $summary['paths']['scenarios']));
        $this->line('Counts:');
        $this->line(' - Providers: '.$summary['counts']['providers']);
        $this->line(' - Transports: '.$summary['counts']['transports']);
        $this->line(' - Captures: '.$summary['counts']['captures']);
        $this->line(' - Scenarios: '.$summary['counts']['scenarios']);

        return self::SUCCESS;
    }

    /**
     * @return array{
     *     paths: array{
     *         storage: array{path: string, exists: bool},
     *         fixtures: array{path: string, exists: bool},
     *         scenarios: array{path: string, exists: bool}
     *     },
     *     counts: array{providers: int, transports: int, captures: int, scenarios: int}
     * }
     */
    private function summary(): array
    {
        $storagePath = $this->configuredPath('storage_path');
        $fixturePath = $this->configuredPath('fixture_path');
        $scenarioPath = $this->configuredPath('scenario_path');

        return [
            'paths' => [
                'storage' => $this->pathSummary($storagePath),
                'fixtures' => $this->pathSummary($fixturePath),
                'scenarios' => $this->pathSummary($scenarioPath),
            ],
            'counts' => [
                'providers' => count($this->providers->all()),
                'transports' => count($this->transports->all()),
                'captures' => count($this->captures->metadataFilePaths()),
                'scenarios' => $this->scenarioFileCount($scenarioPath),
            ],
        ];
    }

    private function configuredPath(string $key): string
    {
        $path = config('preview.'.$key);

        return is_string($path) ? $path : '';
    }

    /** @return array{path: string, exists: bool} */
    private function pathSummary(string $path): array
    {
        return [
            'path' => $path,
            'exists' => is_dir($path),
        ];
    }

    private function scenarioFileCount(string $path): int
    {
        if (! is_dir($path)) {
            return 0;
        }

        $files = glob(rtrim($path, DIRECTORY_SEPARATOR).'/*.php');

        return is_array($files) ? count($files) : 0;
    }

    /** @param array{path: string, exists: bool} $path */
    private function pathLine(string $label, array $path): string
    {
        return sprintf(
            ' - %s: %s (exists: %s)',
            $label,
            $path['path'],
            $path['exists'] ? 'yes' : 'no',
        );
    }
}

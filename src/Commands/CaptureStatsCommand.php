<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use Oxhq\Preview\Capture\CaptureRecord;
use Oxhq\Preview\Capture\CaptureRepository;

final class CaptureStatsCommand extends Command
{
    protected $signature = 'preview:capture:stats
        {--json : Emit machine-readable JSON output}';

    protected $description = 'Summarize local Preview capture inventory.';

    public function __construct(private readonly CaptureRepository $captures)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $stats = $this->stats($this->captures->all());

        if ((bool) $this->option('json')) {
            $this->line($this->json($stats));

            return self::SUCCESS;
        }

        $this->line('Total captures: '.$stats['total']);
        $this->line('Verified: '.$stats['verified']);
        $this->line('Unverified: '.$stats['unverified']);
        $this->line('Oldest captured_at: '.($stats['oldest_captured_at'] ?? 'none'));
        $this->line('Newest captured_at: '.($stats['newest_captured_at'] ?? 'none'));

        $this->table(['Provider', 'Captures'], $this->rows($stats['by_provider']));
        $this->table(['Event Type', 'Captures'], $this->rows($stats['by_event_type']));

        return self::SUCCESS;
    }

    /**
     * @param list<CaptureRecord> $records
     * @return array{
     *     total: int,
     *     verified: int,
     *     unverified: int,
     *     by_provider: array<string, int>,
     *     by_event_type: array<string, int>,
     *     oldest_captured_at: string|null,
     *     newest_captured_at: string|null
     * }
     */
    private function stats(array $records): array
    {
        $verified = 0;
        $byProvider = [];
        $byEventType = [];
        $oldest = null;
        $newest = null;

        foreach ($records as $record) {
            if ($record->verified) {
                $verified++;
            }

            $byProvider[$record->provider] = ($byProvider[$record->provider] ?? 0) + 1;

            $eventType = $record->eventType ?? 'unknown';
            $byEventType[$eventType] = ($byEventType[$eventType] ?? 0) + 1;

            if ($oldest === null || $record->capturedAt < $oldest) {
                $oldest = $record->capturedAt;
            }

            if ($newest === null || $record->capturedAt > $newest) {
                $newest = $record->capturedAt;
            }
        }

        ksort($byProvider);
        ksort($byEventType);

        $total = count($records);

        return [
            'total' => $total,
            'verified' => $verified,
            'unverified' => $total - $verified,
            'by_provider' => $byProvider,
            'by_event_type' => $byEventType,
            'oldest_captured_at' => $oldest?->format(DATE_ATOM),
            'newest_captured_at' => $newest?->format(DATE_ATOM),
        ];
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
}

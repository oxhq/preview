<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use Oxhq\Preview\Capture\CaptureRecord;
use Oxhq\Preview\Capture\CaptureRepository;

final class CaptureTimelineCommand extends Command
{
    protected $signature = 'preview:capture:timeline
        {--provider= : Only include captures for this provider}
        {--json : Emit machine-readable JSON output}';

    protected $description = 'Show a safe chronological Preview capture timeline.';

    public function __construct(private readonly CaptureRepository $captures)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $summary = $this->summary($this->filteredRecords());

        if ((bool) $this->option('json')) {
            $this->line($this->json($summary));

            return self::SUCCESS;
        }

        $this->line('Capture timeline');
        $this->line('Count: '.$summary['count']);
        $this->line('First captured_at: '.($summary['first'] ?? 'none'));
        $this->line('Last captured_at: '.($summary['last'] ?? 'none'));

        if ($summary['captures'] === []) {
            $this->line('No captures found.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Provider', 'Event', 'Path', 'Verified', 'Body Bytes'],
            array_map(
                fn (array $capture): array => [
                    $capture['id'],
                    $capture['provider'],
                    $capture['event'] ?? 'unknown',
                    $capture['path'],
                    $capture['verified'] ? 'yes' : 'no',
                    $capture['body_bytes'],
                ],
                $summary['captures'],
            ),
        );

        return self::SUCCESS;
    }

    /**
     * @return list<CaptureRecord>
     */
    private function filteredRecords(): array
    {
        $provider = $this->option('provider');
        $records = $this->captures->all();

        if (is_string($provider) && $provider !== '') {
            $records = array_values(array_filter(
                $records,
                fn (CaptureRecord $record): bool => $record->provider === $provider,
            ));
        }

        usort(
            $records,
            fn (CaptureRecord $left, CaptureRecord $right): int => $left->capturedAt <=> $right->capturedAt,
        );

        return $records;
    }

    /**
     * @param list<CaptureRecord> $records
     * @return array{
     *     count: int,
     *     first: string|null,
     *     last: string|null,
     *     captures: list<array{
     *         id: string,
     *         provider: string,
     *         event: string|null,
     *         path: string,
     *         verified: bool,
     *         body_bytes: int
     *     }>
     * }
     */
    private function summary(array $records): array
    {
        $captures = array_map(
            fn (CaptureRecord $record): array => [
                'id' => $record->id,
                'provider' => $record->provider,
                'event' => $record->eventType,
                'path' => $record->path,
                'verified' => $record->verified,
                'body_bytes' => strlen($record->rawBody()),
            ],
            $records,
        );

        if ($records === []) {
            return [
                'count' => 0,
                'first' => null,
                'last' => null,
                'captures' => [],
            ];
        }

        return [
            'count' => count($records),
            'first' => $records[0]->capturedAt->format(DATE_ATOM),
            'last' => $records[array_key_last($records)]->capturedAt->format(DATE_ATOM),
            'captures' => $captures,
        ];
    }

    /**
     * @param array<string, mixed> $value
     */
    private function json(array $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}

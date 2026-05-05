<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Oxhq\Preview\Capture\CaptureRecord;
use Oxhq\Preview\Capture\CaptureRepository;
use Throwable;

final class CapturePruneCommand extends Command
{
    protected $signature = 'preview:capture:prune
        {--before= : Delete captures older than this YYYY-MM-DD cutoff}
        {--dry-run : List matching captures without deleting them}
        {--json : Emit machine-readable JSON output}';

    protected $description = 'Prune local Preview captures older than an explicit cutoff.';

    public function __construct(private readonly CaptureRepository $captures)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $before = $this->option('before');

        if (! is_string($before) || $before === '') {
            $this->error('Provide --before=YYYY-MM-DD before pruning captures.');

            return self::FAILURE;
        }

        $cutoff = $this->cutoff($before);

        if ($cutoff === null) {
            $this->error('The --before option must use YYYY-MM-DD.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        try {
            $records = $this->captures->pruneBefore($cutoff, $dryRun);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $ids = array_map(fn (CaptureRecord $record): string => $record->id, $records);

        if ((bool) $this->option('json')) {
            $this->line($this->json([
                'before' => $before,
                'dry_run' => $dryRun,
                'count' => count($ids),
                'ids' => $ids,
            ]));

            return self::SUCCESS;
        }

        $count = count($ids);
        $captureLabel = $count === 1 ? 'capture' : 'captures';

        if ($dryRun) {
            $this->line("Dry run: {$count} {$captureLabel} would be pruned.");
        } else {
            $this->info("Pruned {$count} {$captureLabel}.");
        }

        $this->line('IDs: '.($ids === [] ? 'none' : implode(', ', $ids)));

        return self::SUCCESS;
    }

    private function cutoff(string $before): ?DateTimeImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $before)) {
            return null;
        }

        $cutoff = DateTimeImmutable::createFromFormat('!Y-m-d', $before, new DateTimeZone('UTC'));

        if (! $cutoff instanceof DateTimeImmutable || $cutoff->format('Y-m-d') !== $before) {
            return null;
        }

        return $cutoff;
    }

    /**
     * @param array<string, mixed> $value
     */
    private function json(array $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}

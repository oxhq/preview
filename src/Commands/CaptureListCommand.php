<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use Oxhq\Preview\Capture\CaptureRepository;

final class CaptureListCommand extends Command
{
    protected $signature = 'preview:capture:list';

    protected $description = 'List local Preview captures.';

    public function __construct(private readonly CaptureRepository $captures)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $records = $this->captures->all();

        if ($records === []) {
            $this->line('No captures found.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Provider', 'Event', 'Endpoint', 'Verification', 'Captured At'],
            array_map(
                fn ($record): array => [
                    $record->id,
                    $record->provider,
                    $record->eventType ?? '-',
                    "{$record->method} {$record->path}",
                    $record->verified ? 'verified' : 'not verified',
                    $record->capturedAt->format(DATE_ATOM),
                ],
                $records,
            ),
        );

        return self::SUCCESS;
    }
}

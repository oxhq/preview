<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use Oxhq\Preview\Capture\CaptureRepository;

final class CaptureListCommand extends Command
{
    protected $signature = 'preview:capture:list
        {--json : Emit machine-readable JSON output}';

    protected $description = 'List local Preview captures.';

    public function __construct(private readonly CaptureRepository $captures)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $records = $this->captures->all();

        if ((bool) $this->option('json')) {
            $this->line($this->json(array_map(
                fn ($record): array => [
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
                ],
                $records,
            )));

            return self::SUCCESS;
        }

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

    /**
     * @param array<mixed> $value
     */
    private function json(array $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}

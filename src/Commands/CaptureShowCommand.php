<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use Oxhq\Preview\Capture\CaptureRepository;
use Throwable;

final class CaptureShowCommand extends Command
{
    protected $signature = 'preview:capture:show
        {capture : Capture ID}
        {--json : Emit machine-readable JSON output}';

    protected $description = 'Show redacted Preview capture metadata.';

    public function __construct(private readonly CaptureRepository $captures)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $record = $this->captures->find((string) $this->argument('capture'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode([
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
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}

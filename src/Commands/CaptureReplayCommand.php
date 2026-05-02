<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use Oxhq\Preview\Capture\ReplayService;
use Throwable;

final class CaptureReplayCommand extends Command
{
    protected $signature = 'preview:capture:replay
        {capture : Capture ID}
        {--exact : Replay captured headers and body exactly}
        {--resign : Replay with provider-fresh signed headers}';

    protected $description = 'Build a local Preview replay payload.';

    public function __construct(private readonly ReplayService $replay)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $exact = (bool) $this->option('exact');
        $resign = (bool) $this->option('resign');

        if ($exact === $resign) {
            $this->error('Choose exactly one replay mode: --exact or --resign.');

            return self::FAILURE;
        }

        try {
            $payload = $this->replay->replay((string) $this->argument('capture'), $resign ? 'resign' : 'exact');
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Replay payload ready for capture [{$payload['id']}] using [{$payload['mode']}].");
        $this->line("Endpoint: {$payload['method']} {$payload['path']}");
        $this->line('Headers: '.count((array) $payload['headers']));
        $this->line('Body bytes: '.strlen((string) $payload['raw_body']));

        return self::SUCCESS;
    }
}

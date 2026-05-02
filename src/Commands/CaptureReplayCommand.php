<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use Oxhq\Preview\Capture\HttpReplayDispatcher;
use Oxhq\Preview\Capture\ReplayService;
use Throwable;

final class CaptureReplayCommand extends Command
{
    protected $signature = 'preview:capture:replay
        {capture : Capture ID}
        {--exact : Replay captured headers and body exactly}
        {--resign : Replay with provider-fresh signed headers}
        {--send-to= : Optional absolute target base URL or full URL to dispatch the replay over HTTP}';

    protected $description = 'Build a local Preview replay payload.';

    public function __construct(
        private readonly ReplayService $replay,
        private readonly HttpReplayDispatcher $dispatcher,
    ) {
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

        $target = $this->option('send-to');

        if ($target !== null) {
            try {
                $result = $this->dispatcher->dispatch($payload, (string) $target);
            } catch (Throwable $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            $this->line("Replay HTTP status: {$result->statusCode}");
            $this->line('Replay dispatch: '.($result->successful() ? 'success' : 'failure'));

            return $result->successful() ? self::SUCCESS : self::FAILURE;
        }

        return self::SUCCESS;
    }
}

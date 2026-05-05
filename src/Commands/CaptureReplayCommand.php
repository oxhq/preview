<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use Oxhq\Preview\Capture\HttpReplayDispatcher;
use Oxhq\Preview\Capture\ReplayService;
use Oxhq\Preview\Core\RedactionPolicy;
use Throwable;

final class CaptureReplayCommand extends Command
{
    protected $signature = 'preview:capture:replay
        {capture : Capture ID}
        {--exact : Replay captured headers and body exactly}
        {--resign : Replay with provider-fresh signed headers}
        {--send-to= : Optional absolute target base URL or full URL to dispatch the replay over HTTP}
        {--json : Emit machine-readable JSON output}';

    protected $description = 'Build a local Preview replay payload.';

    public function __construct(
        private readonly ReplayService $replay,
        private readonly HttpReplayDispatcher $dispatcher,
        private readonly RedactionPolicy $redactionPolicy,
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

        $json = (bool) $this->option('json');

        if (! $json) {
            $this->info("Replay payload ready for capture [{$payload['id']}] using [{$payload['mode']}].");
            $this->line("Endpoint: {$payload['method']} {$payload['path']}");
            $this->line('Headers: '.count((array) $payload['headers']));
            $this->line('Body bytes: '.strlen((string) $payload['raw_body']));
        }

        $target = $this->option('send-to');
        $dispatch = null;

        if ($target !== null) {
            try {
                $result = $this->dispatcher->dispatch($payload, (string) $target);
            } catch (Throwable $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            $dispatch = [
                'target' => (string) $target,
                'status_code' => $result->statusCode,
                'successful' => $result->successful(),
            ];

            if (! $json) {
                $this->line("Replay HTTP status: {$result->statusCode}");
                $this->line('Replay dispatch: '.($result->successful() ? 'success' : 'failure'));
            }

            if ($json) {
                $this->line($this->json($this->safePayload($payload, $dispatch)));
            }

            return $result->successful() ? self::SUCCESS : self::FAILURE;
        }

        if ($json) {
            $this->line($this->json($this->safePayload($payload)));
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{target: string, status_code: int, successful: bool}|null $dispatch
     * @return array<string, mixed>
     */
    private function safePayload(array $payload, ?array $dispatch = null): array
    {
        $summary = [
            'id' => $payload['id'],
            'mode' => $payload['mode'],
            'provider' => $payload['provider'],
            'event_type' => $payload['event_type'],
            'method' => $payload['method'],
            'path' => $payload['path'],
            'query' => $payload['query'],
            'headers' => $this->redactionPolicy->redactHeaders((array) $payload['headers']),
            'raw_body_bytes' => strlen((string) $payload['raw_body']),
            'captured_at' => $payload['captured_at'],
        ];

        if ($dispatch !== null) {
            $summary['dispatch'] = $dispatch;
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $value
     */
    private function json(array $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}

<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use Oxhq\Preview\Capture\CaptureRecord;
use Oxhq\Preview\Capture\CaptureRepository;
use Oxhq\Preview\Capture\PreviewRequest;
use Oxhq\Preview\Core\ProviderRegistry;
use Throwable;

final class CaptureVerifyCommand extends Command
{
    protected $signature = 'preview:capture:verify
        {capture : Capture ID}
        {--json : Emit machine-readable JSON output}';

    protected $description = 'Re-run provider verification against a stored Preview capture.';

    public function __construct(
        private readonly CaptureRepository $captures,
        private readonly ProviderRegistry $providers,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $record = $this->captures->find((string) $this->argument('capture'));
            $provider = $this->providers->get($record->provider, $this->providerContext($record));
            $request = $this->requestFrom($record);
            $verification = $provider->verify($request);
            $verificationMessage = $this->safeVerificationMessage($verification->message, $request);
            $eventType = $provider->eventType($request);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line($this->json([
                'capture_id' => $record->id,
                'provider' => $record->provider,
                'verified' => $verification->verified,
                'verification_message' => $verificationMessage,
                'event_type' => $eventType,
            ]));

            return $verification->verified ? self::SUCCESS : self::FAILURE;
        }

        $this->line("Capture [{$record->id}] verification: ".($verification->verified ? 'verified' : 'not verified'));
        $this->line("Provider: {$record->provider}");
        $this->line('Event type: '.($eventType ?? 'unknown'));

        if ($verificationMessage !== null) {
            $this->line("Verification message: {$verificationMessage}");
        }

        return $verification->verified ? self::SUCCESS : self::FAILURE;
    }

    private function safeVerificationMessage(?string $message, PreviewRequest $request): ?string
    {
        if ($message === null) {
            return null;
        }

        foreach ($this->sensitiveValues($request) as $value) {
            $message = str_replace($value, '[redacted]', $message);
        }

        return $message;
    }

    /**
     * @return list<string>
     */
    private function sensitiveValues(PreviewRequest $request): array
    {
        $values = [$request->rawBody];

        array_push($values, ...$this->scalarValues($request->headers));
        $values = array_values(array_unique(array_filter(
            array_map(fn (mixed $value): string => (string) $value, $values),
            fn (string $value): bool => $value !== '',
        )));

        usort($values, fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        return $values;
    }

    /**
     * @param array<mixed> $values
     * @return list<string>
     */
    private function scalarValues(array $values): array
    {
        $scalars = [];

        foreach ($values as $value) {
            if (is_array($value)) {
                array_push($scalars, ...$this->scalarValues($value));

                continue;
            }

            if (is_scalar($value)) {
                $scalars[] = (string) $value;
            }
        }

        return $scalars;
    }

    private function requestFrom(CaptureRecord $record): PreviewRequest
    {
        return new PreviewRequest(
            provider: $record->provider,
            method: $record->method,
            path: $record->path,
            query: $record->query,
            headers: $record->rawHeaders(),
            rawBody: $record->rawBody(),
            capturedAt: $record->capturedAt,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function providerContext(CaptureRecord $record): array
    {
        $context = $record->metadata['fixture_context'] ?? [];

        return is_array($context) ? $context : [];
    }

    /**
     * @param array<string, mixed> $value
     */
    private function json(array $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}

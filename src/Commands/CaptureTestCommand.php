<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use Oxhq\Preview\Capture\CaptureRepository;
use Oxhq\Preview\Core\ProviderRegistry;
use Oxhq\Preview\Testing\PestTestWriter;
use Throwable;

final class CaptureTestCommand extends Command
{
    protected $signature = 'preview:capture:test
        {capture : Capture ID}
        {--json : Emit machine-readable JSON output}';

    protected $description = 'Generate a Pest test from a Preview capture.';

    public function __construct(
        private readonly CaptureRepository $captures,
        private readonly ProviderRegistry $providers,
        private readonly PestTestWriter $tests,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $record = $this->captures->find((string) $this->argument('capture'));
            $provider = $this->providers->get($record->provider);
            $canSign = $provider->canSign();
            $path = $this->tests->write($record, $canSign);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line($this->json([
                'id' => $record->id,
                'provider' => $record->provider,
                'test_path' => $path,
                'can_sign' => $canSign,
            ]));

            return self::SUCCESS;
        }

        $this->info("Pest test generated for capture [{$record->id}].");
        $this->line($path);

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $value
     */
    private function json(array $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}

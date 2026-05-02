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
    protected $signature = 'preview:capture:test {capture : Capture ID}';

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
            $path = $this->tests->write($record, $provider->canSign());
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Pest test generated for capture [{$record->id}].");
        $this->line($path);

        return self::SUCCESS;
    }
}

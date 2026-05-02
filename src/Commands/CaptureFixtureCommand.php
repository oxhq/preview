<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use Oxhq\Preview\Capture\CaptureRepository;
use Oxhq\Preview\Core\ProviderRegistry;
use Oxhq\Preview\Testing\FixtureWriter;
use Throwable;

final class CaptureFixtureCommand extends Command
{
    protected $signature = 'preview:capture:fixture {capture : Capture ID}';

    protected $description = 'Generate provider-aware Preview fixture files.';

    public function __construct(
        private readonly CaptureRepository $captures,
        private readonly ProviderRegistry $providers,
        private readonly FixtureWriter $fixtures,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $record = $this->captures->find((string) $this->argument('capture'));
            $provider = $this->providers->get($record->provider);
            $this->fixtures->write($record, $provider->canSign());
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Fixture generated for capture [{$record->id}].");
        $this->line($this->fixtures->fixturePath($record));

        return self::SUCCESS;
    }
}

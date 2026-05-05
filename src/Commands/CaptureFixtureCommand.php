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
    protected $signature = 'preview:capture:fixture
        {capture : Capture ID}
        {--json : Emit machine-readable JSON output}';

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
            $canSign = $provider->canSign();
            $this->fixtures->write($record, $canSign);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $path = $this->fixtures->fixturePath($record);

        if ((bool) $this->option('json')) {
            $this->line($this->json([
                'id' => $record->id,
                'provider' => $record->provider,
                'fixture_path' => $path,
                'can_sign' => $canSign,
            ]));

            return self::SUCCESS;
        }

        $this->info("Fixture generated for capture [{$record->id}].");
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

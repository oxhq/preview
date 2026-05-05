<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use Oxhq\Preview\Scenario\ScenarioRepository;
use Oxhq\Preview\Testing\ScenarioPestTestWriter;
use RuntimeException;

final class ScenarioTestCommand extends Command
{
    protected $signature = 'preview:scenario:test
        {scenario : Scenario name}
        {--json : Emit machine-readable JSON output}';

    protected $description = 'Generate a Pest-compatible test for a local Laravel Preview scenario.';

    public function __construct(
        private readonly ScenarioRepository $scenarios,
        private readonly ScenarioPestTestWriter $writer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $name = (string) $this->argument('scenario');

        try {
            $scenario = $this->scenarios->find($name);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($scenario === null) {
            $this->error("Scenario [{$name}] was not found.");

            return self::FAILURE;
        }

        try {
            $path = $this->writer->write($scenario);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line($this->json([
                'scenario' => $scenario->name,
                'test_path' => $path,
            ]));

            return self::SUCCESS;
        }

        $this->info("Pest test generated for scenario [{$scenario->name}].");
        $this->line("Path: {$path}");

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

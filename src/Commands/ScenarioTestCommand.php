<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use Oxhq\Preview\Scenario\ScenarioRepository;
use Oxhq\Preview\Testing\ScenarioPestTestWriter;
use RuntimeException;

final class ScenarioTestCommand extends Command
{
    protected $signature = 'preview:scenario:test {scenario : Scenario name}';

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

        $this->info("Pest test generated for scenario [{$scenario->name}].");
        $this->line("Path: {$path}");

        return self::SUCCESS;
    }
}

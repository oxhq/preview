<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use Oxhq\Preview\Scenario\ScenarioRepository;
use RuntimeException;

final class ScenarioShowCommand extends Command
{
    protected $signature = 'preview:scenario:show {scenario : Scenario name}';

    protected $description = 'Show a local Laravel Preview scenario.';

    public function __construct(
        private readonly ScenarioRepository $scenarios,
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

        $this->line("Scenario: {$scenario->name}");
        $this->line('Seed: '.($scenario->seed ?? 'none'));
        $this->line(sprintf('Routes (%d): %s', count($scenario->routes), $this->formatList($scenario->routes)));
        $this->line(sprintf('Captures (%d): %s', count($scenario->captures), $this->formatList($scenario->captures)));
        $this->line(sprintf('Fakes (%d): %s', count($scenario->fakes), $this->formatList($scenario->fakes)));

        if ($scenario->notes !== null && trim($scenario->notes) !== '') {
            $this->line('Notes: '.$scenario->notes);
        }

        return self::SUCCESS;
    }

    /**
     * @param list<string> $values
     */
    private function formatList(array $values): string
    {
        if ($values === []) {
            return 'none';
        }

        return implode(', ', $values);
    }
}

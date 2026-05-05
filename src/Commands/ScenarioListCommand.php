<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use Oxhq\Preview\Scenario\ScenarioRepository;
use RuntimeException;

final class ScenarioListCommand extends Command
{
    protected $signature = 'preview:scenario:list';

    protected $description = 'List local Laravel Preview scenarios.';

    public function __construct(
        private readonly ScenarioRepository $scenarios,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $scenarios = $this->scenarios->all();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($scenarios === []) {
            $this->line('No preview scenarios found.');
            $this->line('Scenario path: '.$this->scenarios->path());

            return self::SUCCESS;
        }

        $this->line('Preview scenarios:');

        foreach ($scenarios as $scenario) {
            $this->line(sprintf(
                ' - %s (captures: %d, routes: %d, route-contexts: %d, fakes: %d)',
                $scenario->name,
                count($scenario->captures),
                count($scenario->routes),
                count(array_filter($scenario->routeContext)),
                count($scenario->fakes),
            ));
        }

        return self::SUCCESS;
    }
}

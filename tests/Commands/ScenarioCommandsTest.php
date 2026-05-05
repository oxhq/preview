<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use Oxhq\Preview\Scenario\Scenario;
use Oxhq\Preview\Tests\TestCase;

final class ScenarioCommandsTest extends TestCase
{
    public function test_preview_scenario_list_prints_scenarios_from_the_configured_path(): void
    {
        $path = $this->scenarioPath();
        $this->app['config']->set('preview.scenario_path', $path);

        $this->writeScenario($path, 'refund.php', <<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;

return new Scenario(name: 'refund-flow');
PHP);
        $this->writeScenario($path, 'checkout.php', <<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;

return new Scenario(name: 'checkout-flow');
PHP);

        $this->artisan('preview:scenario:list')
            ->expectsOutput('Preview scenarios:')
            ->expectsOutput(' - checkout-flow')
            ->expectsOutput(' - refund-flow')
            ->assertExitCode(0);
    }

    public function test_preview_scenario_list_prints_the_configured_path_when_no_scenarios_exist(): void
    {
        $path = $this->scenarioPath();
        $this->app['config']->set('preview.scenario_path', $path);

        $this->artisan('preview:scenario:list')
            ->expectsOutput('No preview scenarios found.')
            ->expectsOutput('Scenario path: '.$path)
            ->assertExitCode(0);
    }

    public function test_preview_scenario_list_rejects_invalid_scenario_files_clearly(): void
    {
        $path = $this->scenarioPath();
        $this->app['config']->set('preview.scenario_path', $path);
        $file = $this->writeScenario($path, 'invalid.php', <<<'PHP'
<?php

return ['name' => 'checkout-flow'];
PHP);

        $this->artisan('preview:scenario:list')
            ->expectsOutput(sprintf(
                'Scenario file [%s] must return an instance of %s.',
                $file,
                Scenario::class,
            ))
            ->assertExitCode(1);
    }

    public function test_preview_scenario_show_prints_scenario_details(): void
    {
        $path = $this->scenarioPath();
        $this->app['config']->set('preview.scenario_path', $path);

        $this->writeScenario($path, 'checkout.php', <<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;

return new Scenario(
    name: 'checkout-flow',
    seed: 'Database\\Seeders\\CheckoutScenarioSeeder',
    routes: ['checkout.show'],
    captures: ['stripe.checkout.completed'],
    fakes: ['mail'],
    notes: 'Happy-path checkout',
);
PHP);

        $this->artisan('preview:scenario:show', ['scenario' => 'checkout-flow'])
            ->expectsOutput('Scenario: checkout-flow')
            ->expectsOutput('Seed: Database\\Seeders\\CheckoutScenarioSeeder')
            ->expectsOutput('Routes: checkout.show')
            ->expectsOutput('Captures: stripe.checkout.completed')
            ->expectsOutput('Fakes: mail')
            ->expectsOutput('Notes: Happy-path checkout')
            ->assertExitCode(0);
    }

    public function test_preview_scenario_show_rejects_missing_scenarios_clearly(): void
    {
        $path = $this->scenarioPath();
        $this->app['config']->set('preview.scenario_path', $path);

        $this->writeScenario($path, 'checkout.php', <<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;

return new Scenario(name: 'checkout-flow');
PHP);

        $this->artisan('preview:scenario:show', ['scenario' => 'missing-flow'])
            ->expectsOutput('Scenario [missing-flow] was not found.')
            ->assertExitCode(1);
    }

    public function test_preview_scenario_show_rejects_invalid_scenario_files_clearly(): void
    {
        $path = $this->scenarioPath();
        $this->app['config']->set('preview.scenario_path', $path);
        $file = $this->writeScenario($path, 'missing-name.php', <<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;

return new Scenario(name: '');
PHP);

        $this->artisan('preview:scenario:show', ['scenario' => 'missing-flow'])
            ->expectsOutput(sprintf(
                'Scenario file [%s] must define a non-empty scenario name.',
                $file,
            ))
            ->assertExitCode(1);
    }

    private function scenarioPath(): string
    {
        return sys_get_temp_dir().'/preview-tests/scenarios/'.spl_object_id($this);
    }

    private function writeScenario(string $path, string $name, string $contents): string
    {
        if (! is_dir($path)) {
            mkdir($path, 0777, true);
        }

        $file = $path.'/'.$name;
        file_put_contents($file, $contents);

        return $file;
    }
}

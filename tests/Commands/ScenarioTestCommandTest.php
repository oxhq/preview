<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use Oxhq\Preview\Tests\TestCase;

final class ScenarioTestCommandTest extends TestCase
{
    public function test_preview_scenario_test_writes_a_pest_test_for_the_named_scenario(): void
    {
        $scenarioPath = $this->scenarioPath();
        $testPath = sys_get_temp_dir().'/preview-tests/generated-scenario-tests/'.spl_object_id($this);
        $this->app['config']->set('preview.scenario_path', $scenarioPath);
        $this->app['config']->set('preview.test_path', $testPath);

        $this->writeScenario($scenarioPath, 'checkout.php', <<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;

return new Scenario(
    name: 'checkout-flow',
    seed: 'Database\\Seeders\\CheckoutScenarioSeeder',
    routes: ['checkout.show', 'checkout.success'],
    captures: ['cap_checkout_completed', 'cap_order_created'],
);
PHP);

        $this->artisan('preview:scenario:test', ['scenario' => 'checkout-flow'])
            ->expectsOutput('Pest test generated for scenario [checkout-flow].')
            ->assertExitCode(0);

        $generated = $testPath.'/Preview/Scenario/checkout-flowTest.php';
        $this->assertFileExists($generated);

        $contents = (string) file_get_contents($generated);

        $this->assertStringContainsString("it('replays checkout-flow preview scenario'", $contents);
        $this->assertStringContainsString('preview:scenario:replay', $contents);
        $this->assertStringContainsString(
            'Precondition: run seed [Database\\Seeders\\CheckoutScenarioSeeder] before replaying this scenario.',
            $contents,
        );
        $this->assertStringContainsString("->expectsOutputToContain('Capture: cap_checkout_completed')", $contents);
        $this->assertStringContainsString("->expectsOutputToContain('Capture: cap_order_created')", $contents);
        $this->assertStringContainsString(
            'TODO route scenario execution: checkout.show (metadata only until route scenario test execution is implemented)',
            $contents,
        );
        $this->assertStringContainsString(
            'TODO route scenario execution: checkout.success (metadata only until route scenario test execution is implemented)',
            $contents,
        );
    }

    public function test_preview_scenario_test_rejects_missing_scenarios_clearly(): void
    {
        $scenarioPath = $this->scenarioPath();
        $this->app['config']->set('preview.scenario_path', $scenarioPath);

        $this->writeScenario($scenarioPath, 'checkout.php', <<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;

return new Scenario(name: 'checkout-flow');
PHP);

        $this->artisan('preview:scenario:test', ['scenario' => 'missing-flow'])
            ->expectsOutput('Scenario [missing-flow] was not found.')
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

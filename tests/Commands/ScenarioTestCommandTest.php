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
        $this->assertPhpFileIsLintable($generated);

        $contents = (string) file_get_contents($generated);

        $this->assertStringContainsString('use Oxhq\\Preview\\Scenario\\ScenarioRunner;', $contents);
        $this->assertStringContainsString("it('replays checkout-flow preview scenario'", $contents);
        $this->assertStringContainsString("app(ScenarioRunner::class)->replay('checkout-flow', 'exact')", $contents);
        $this->assertStringNotContainsString('preview:scenario:replay', $contents);
        $this->assertStringContainsString(
            'Precondition: run seed [Database\\Seeders\\CheckoutScenarioSeeder] before replaying this scenario.',
            $contents,
        );
        $this->assertStringContainsString("\$this->assertSame('checkout-flow', \$result->scenario->name);", $contents);
        $this->assertStringContainsString("\$this->assertSame('exact', \$result->mode);", $contents);
        $this->assertStringContainsString("\$this->assertSame('Database\\\\Seeders\\\\CheckoutScenarioSeeder', \$result->seed);", $contents);
        $this->assertStringContainsString('$this->assertCount(2, $result->captures);', $contents);
        $this->assertStringContainsString("\$this->assertSame('cap_checkout_completed', \$result->captures[0]['id'] ?? null);", $contents);
        $this->assertStringContainsString("\$this->assertSame('cap_order_created', \$result->captures[1]['id'] ?? null);", $contents);
        $this->assertStringContainsString('$this->assertCount(2, $result->dispatches);', $contents);
        $this->assertStringContainsString(
            'Route replay expected: checkout.show',
            $contents,
        );
        $this->assertStringContainsString(
            'Route replay expected: checkout.success',
            $contents,
        );
        $this->assertStringContainsString('$this->assertCount(2, $result->routes);', $contents);
        $this->assertStringContainsString("\$this->assertSame('checkout.show', \$result->routes[0]->preview->name);", $contents);
        $this->assertStringContainsString('$this->assertTrue($result->routes[0]->successful());', $contents);
        $this->assertStringContainsString('$this->assertGreaterThanOrEqual(200, $result->routes[0]->response->getStatusCode());', $contents);
        $this->assertStringContainsString('$this->assertLessThan(300, $result->routes[0]->response->getStatusCode());', $contents);
        $this->assertStringContainsString("\$this->assertSame('checkout.success', \$result->routes[1]->preview->name);", $contents);
        $this->assertStringContainsString('$this->assertTrue($result->routes[1]->successful());', $contents);
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

    private function assertPhpFileIsLintable(string $path): void
    {
        $output = [];
        $exitCode = 1;

        exec(PHP_BINARY.' -l '.escapeshellarg($path), $output, $exitCode);

        $this->assertSame(0, $exitCode, implode(PHP_EOL, $output));
    }
}

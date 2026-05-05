<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Scenario;

use Oxhq\Preview\Scenario\Scenario;
use Oxhq\Preview\Scenario\ScenarioRepository;
use Oxhq\Preview\Tests\TestCase;
use RuntimeException;

final class ScenarioRepositoryTest extends TestCase
{
    public function test_it_loads_php_scenario_files_that_return_scenarios(): void
    {
        $path = $this->scenarioPath();
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

        $scenario = (new ScenarioRepository($path))->find('checkout-flow');

        $this->assertInstanceOf(Scenario::class, $scenario);
        $this->assertSame('checkout-flow', $scenario->name);
        $this->assertSame('Database\\Seeders\\CheckoutScenarioSeeder', $scenario->seed);
        $this->assertSame(['checkout.show'], $scenario->routes);
        $this->assertSame(['stripe.checkout.completed'], $scenario->captures);
        $this->assertSame(['mail'], $scenario->fakes);
        $this->assertSame('Happy-path checkout', $scenario->notes);
    }

    public function test_it_lists_scenarios_from_the_configured_path_in_name_order(): void
    {
        $path = $this->scenarioPath();
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

        $scenarios = (new ScenarioRepository($path))->all();

        $this->assertSame(['checkout-flow', 'refund-flow'], array_map(
            static fn (Scenario $scenario): string => $scenario->name,
            $scenarios,
        ));
    }

    public function test_it_returns_an_empty_list_when_the_scenario_path_does_not_exist(): void
    {
        $this->assertSame([], (new ScenarioRepository($this->scenarioPath().'/missing'))->all());
    }

    public function test_it_rejects_files_that_do_not_return_a_scenario(): void
    {
        $path = $this->scenarioPath();
        $file = $this->writeScenario($path, 'invalid.php', <<<'PHP'
<?php

return ['name' => 'checkout-flow'];
PHP);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf(
            'Scenario file [%s] must return an instance of %s.',
            $file,
            Scenario::class,
        ));

        (new ScenarioRepository($path))->all();
    }

    public function test_it_rejects_scenarios_without_a_clear_name(): void
    {
        $path = $this->scenarioPath();
        $file = $this->writeScenario($path, 'missing-name.php', <<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;

return new Scenario(name: '   ');
PHP);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf(
            'Scenario file [%s] must define a non-empty scenario name.',
            $file,
        ));

        (new ScenarioRepository($path))->all();
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

<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Scenario;

use Oxhq\Preview\Scenario\Scenario;
use Oxhq\Preview\Scenario\ScenarioRepository;
use Oxhq\Preview\Tests\TestCase;
use RuntimeException;

final class ScenarioValidationTest extends TestCase
{
    public function test_it_normalizes_supported_fakes_and_deduplicates_routes_and_captures_in_declared_order(): void
    {
        $path = $this->scenarioPath();
        $this->writeScenario($path, 'checkout.php', <<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;

return new Scenario(
    name: 'checkout-flow',
    routes: [' checkout.show ', 'checkout.show', 'checkout.status'],
    captures: [' stripe.checkout.completed ', 'stripe.checkout.completed', 'github.pull_request'],
    fakes: [' Mail ', 'mail', 'EVENTS', 'http'],
);
PHP);

        $scenario = (new ScenarioRepository($path))->find('checkout-flow');

        $this->assertInstanceOf(Scenario::class, $scenario);
        $this->assertSame(['checkout.show', 'checkout.status'], $scenario->routes);
        $this->assertSame(['stripe.checkout.completed', 'github.pull_request'], $scenario->captures);
        $this->assertSame(['mail', 'events', 'http'], $scenario->fakes);
    }

    public function test_it_rejects_unsupported_scenario_fakes_clearly(): void
    {
        $path = $this->scenarioPath();
        $file = $this->writeScenario($path, 'unsupported-fake.php', <<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;

return new Scenario(
    name: 'unsupported-fake-flow',
    fakes: ['mail', 'cache'],
);
PHP);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf(
            'Scenario file [%s] defines unsupported fake [cache]. Supported fakes: queue, mail, http, events.',
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

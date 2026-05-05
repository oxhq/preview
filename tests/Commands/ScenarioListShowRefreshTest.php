<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use Oxhq\Preview\Tests\TestCase;

final class ScenarioListShowRefreshTest extends TestCase
{
    public function test_preview_scenario_list_exposes_route_capture_and_fake_counts(): void
    {
        $path = $this->scenarioPath();
        $this->app['config']->set('preview.scenario_path', $path);

        $this->writeScenario($path, 'checkout.php', <<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;

return new Scenario(
    name: 'checkout-flow',
    routes: ['checkout.show', 'checkout.status'],
    captures: ['stripe.checkout.completed'],
    fakes: ['mail', 'queue'],
);
PHP);
        $this->writeScenario($path, 'refund.php', <<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;

return new Scenario(
    name: 'refund-flow',
    captures: ['stripe.refund.created', 'github.issue.created'],
);
PHP);

        $this->artisan('preview:scenario:list')
            ->expectsOutput('Preview scenarios:')
            ->expectsOutput(' - checkout-flow (captures: 1, routes: 2, fakes: 2)')
            ->expectsOutput(' - refund-flow (captures: 2, routes: 0, fakes: 0)')
            ->assertExitCode(0);
    }

    public function test_preview_scenario_show_exposes_route_capture_and_fake_counts_with_details(): void
    {
        $path = $this->scenarioPath();
        $this->app['config']->set('preview.scenario_path', $path);

        $this->writeScenario($path, 'checkout.php', <<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;

return new Scenario(
    name: 'checkout-flow',
    seed: 'Database\\Seeders\\CheckoutScenarioSeeder',
    routes: ['checkout.show', 'checkout.status'],
    captures: ['stripe.checkout.completed', 'github.pull_request'],
    fakes: ['mail', 'queue', 'http'],
);
PHP);

        $this->artisan('preview:scenario:show', ['scenario' => 'checkout-flow'])
            ->expectsOutput('Scenario: checkout-flow')
            ->expectsOutput('Seed: Database\\Seeders\\CheckoutScenarioSeeder')
            ->expectsOutput('Routes (2): checkout.show, checkout.status')
            ->expectsOutput('Captures (2): stripe.checkout.completed, github.pull_request')
            ->expectsOutput('Fakes (3): mail, queue, http')
            ->assertExitCode(0);
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

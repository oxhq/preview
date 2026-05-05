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
    routeContext: [
        'checkout.show' => [
            'session' => ['tenant' => 'acme'],
            'readonly_db' => true,
        ],
    ],
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
            ->expectsOutput(' - checkout-flow (captures: 1, routes: 2, route-contexts: 1, fakes: 2)')
            ->expectsOutput(' - refund-flow (captures: 2, routes: 0, route-contexts: 0, fakes: 0)')
            ->assertExitCode(0);
    }

    public function test_preview_scenario_list_outputs_machine_readable_json(): void
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
    routeContext: [
        'checkout.show' => ['session' => ['tenant' => 'acme']],
    ],
    routeExpectations: [
        'checkout.show' => ['status' => '200', 'outputContains' => 'Ready'],
    ],
    captures: ['stripe.checkout.completed'],
    fakes: ['mail', 'queue'],
    notes: 'Happy-path checkout',
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

        $this->artisan('preview:scenario:list', ['--json' => true])
            ->expectsOutput(json_encode([
                [
                    'name' => 'checkout-flow',
                    'seed' => 'Database\\Seeders\\CheckoutScenarioSeeder',
                    'route_count' => 2,
                    'capture_count' => 1,
                    'fake_count' => 2,
                    'route_context_count' => 1,
                    'route_expectation_count' => 1,
                    'notes' => 'Happy-path checkout',
                ],
                [
                    'name' => 'refund-flow',
                    'seed' => null,
                    'route_count' => 0,
                    'capture_count' => 2,
                    'fake_count' => 0,
                    'route_context_count' => 0,
                    'route_expectation_count' => 0,
                    'notes' => null,
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
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
    routeContext: [
        'checkout.status' => [
            'session' => [
                'tenant' => 'acme',
                'mode' => 'review',
            ],
            'guard' => 'client',
            'readonly_db' => true,
            'fakes' => ['http'],
        ],
        'checkout.show' => [
            'user_id' => '42',
            'user_model' => 'App\\Models\\User',
            'fakes' => ['queue', 'mail'],
        ],
    ],
    captures: ['stripe.checkout.completed', 'github.pull_request'],
    fakes: ['mail', 'queue', 'http'],
    routeExpectations: [
        'checkout.status' => [
            'status' => 202,
            'output_contains' => 'Processing',
        ],
        'checkout.show' => [
            'status' => '200',
        ],
    ],
);
PHP);

        $this->artisan('preview:scenario:show', ['scenario' => 'checkout-flow'])
            ->expectsOutput('Scenario: checkout-flow')
            ->expectsOutput('Seed: Database\\Seeders\\CheckoutScenarioSeeder')
            ->expectsOutput('Routes (2): checkout.show, checkout.status')
            ->expectsOutput('Captures (2): stripe.checkout.completed, github.pull_request')
            ->expectsOutput('Fakes (3): mail, queue, http')
            ->expectsOutput('Route context (2):')
            ->expectsOutput(' - checkout.show: session keys (0): none; guard: none; user: 42 via App\\Models\\User; readonly-db: not requested; fakes: mail, queue')
            ->expectsOutput(' - checkout.status: session keys (2): mode, tenant; guard: client; user: none; readonly-db: requested; fakes: http')
            ->expectsOutput('Route expectations (2):')
            ->expectsOutput(' - checkout.show: status 200; output contains: none')
            ->expectsOutput(' - checkout.status: status 202; output contains: Processing')
            ->assertExitCode(0);
    }

    public function test_preview_scenario_show_outputs_machine_readable_json(): void
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
    routeParameters: [
        'checkout.show' => ['tenant' => 'acme'],
    ],
    routeContext: [
        'checkout.show' => [
            'session' => ['tenant' => 'acme'],
            'guard' => 'client',
            'userId' => '42',
            'userModel' => 'App\\Models\\User',
            'readonlyDb' => true,
            'fakes' => ['queue', 'mail'],
        ],
    ],
    routeExpectations: [
        'checkout.show' => [
            'status' => '200',
            'outputContains' => 'Ready',
        ],
    ],
    captures: ['stripe.checkout.completed'],
    fakes: ['mail', 'queue'],
    notes: 'Happy-path checkout',
);
PHP);

        $this->artisan('preview:scenario:show', ['scenario' => 'checkout-flow', '--json' => true])
            ->expectsOutput(json_encode([
                'name' => 'checkout-flow',
                'seed' => 'Database\\Seeders\\CheckoutScenarioSeeder',
                'routes' => ['checkout.show'],
                'routeParameters' => [
                    'checkout.show' => ['tenant' => 'acme'],
                ],
                'routeContext' => [
                    'checkout.show' => [
                        'session' => ['tenant' => 'acme'],
                        'guard' => 'client',
                        'user_id' => '42',
                        'user_model' => 'App\\Models\\User',
                        'readonly_db' => true,
                        'fakes' => ['queue', 'mail'],
                    ],
                ],
                'routeExpectations' => [
                    'checkout.show' => [
                        'status' => 200,
                        'output_contains' => 'Ready',
                    ],
                ],
                'captures' => ['stripe.checkout.completed'],
                'fakes' => ['mail', 'queue'],
                'notes' => 'Happy-path checkout',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
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

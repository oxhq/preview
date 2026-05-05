<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use Oxhq\Preview\Scenario\ScenarioRepository;
use Oxhq\Preview\Tests\TestCase;

final class ScenarioMakeCommandTest extends TestCase
{
    public function test_preview_scenario_make_writes_a_scenario_file_from_options(): void
    {
        $path = $this->scenarioPath();
        $this->app['config']->set('preview.scenario_path', $path);

        $this->artisan('preview:scenario:make', [
            'name' => 'checkout-flow',
            '--capture' => ['stripe.checkout.completed', 'github.pull_request'],
            '--route' => ['checkout.show', 'orders.show'],
            '--param' => ['checkout.show:tenant=acme', 'orders.show.order=42'],
            '--route-session' => ['checkout.show:currency=usd', 'orders.show.locale=en'],
            '--route-guard' => ['checkout.show=web'],
            '--route-user' => ['checkout.show:42:App\\Models\\User'],
            '--route-readonly-db' => ['checkout.show'],
            '--route-fake' => ['checkout.show:mail', 'checkout.show:events'],
            '--fake' => ['mail', 'queue'],
            '--seed' => 'Database\\Seeders\\CheckoutScenarioSeeder',
            '--note' => 'Happy-path checkout',
        ])
            ->expectsOutput('Scenario [checkout-flow] created.')
            ->expectsOutput($path.DIRECTORY_SEPARATOR.'checkout-flow.php')
            ->assertExitCode(0);

        $scenario = (new ScenarioRepository($path))->find('checkout-flow');

        $this->assertNotNull($scenario);
        $this->assertSame('Database\\Seeders\\CheckoutScenarioSeeder', $scenario->seed);
        $this->assertSame(['checkout.show', 'orders.show'], $scenario->routes);
        $this->assertSame([
            'checkout.show' => ['tenant' => 'acme'],
            'orders.show' => ['order' => '42'],
        ], $scenario->routeParameters);
        $this->assertSame([
            'checkout.show' => [
                'session' => ['currency' => 'usd'],
                'guard' => 'web',
                'user_id' => '42',
                'user_model' => 'App\\Models\\User',
                'readonly_db' => true,
                'fakes' => ['mail', 'events'],
            ],
            'orders.show' => [
                'session' => ['locale' => 'en'],
            ],
        ], $scenario->routeContext);
        $this->assertSame(['stripe.checkout.completed', 'github.pull_request'], $scenario->captures);
        $this->assertSame(['mail', 'queue'], $scenario->fakes);
        $this->assertSame('Happy-path checkout', $scenario->notes);
    }

    public function test_preview_scenario_make_refuses_to_overwrite_existing_files_without_force(): void
    {
        $path = $this->scenarioPath();
        $this->app['config']->set('preview.scenario_path', $path);

        mkdir($path, 0777, true);
        file_put_contents($path.DIRECTORY_SEPARATOR.'checkout-flow.php', '<?php return "existing";');

        $this->artisan('preview:scenario:make', ['name' => 'checkout-flow'])
            ->expectsOutput('Scenario file ['.$path.DIRECTORY_SEPARATOR.'checkout-flow.php] already exists. Pass --force to overwrite it.')
            ->assertExitCode(1);

        $this->assertSame('<?php return "existing";', file_get_contents($path.DIRECTORY_SEPARATOR.'checkout-flow.php'));
    }

    public function test_preview_scenario_make_can_force_overwrite_existing_files(): void
    {
        $path = $this->scenarioPath();
        $this->app['config']->set('preview.scenario_path', $path);

        mkdir($path, 0777, true);
        file_put_contents($path.DIRECTORY_SEPARATOR.'checkout-flow.php', '<?php return "existing";');

        $this->artisan('preview:scenario:make', [
            'name' => 'checkout-flow',
            '--route' => ['checkout.show'],
            '--force' => true,
        ])
            ->expectsOutput('Scenario [checkout-flow] created.')
            ->expectsOutput($path.DIRECTORY_SEPARATOR.'checkout-flow.php')
            ->assertExitCode(0);

        $scenario = (new ScenarioRepository($path))->find('checkout-flow');

        $this->assertNotNull($scenario);
        $this->assertSame(['checkout.show'], $scenario->routes);
    }

    public function test_preview_scenario_make_rejects_invalid_route_context_input(): void
    {
        $path = $this->scenarioPath();
        $this->app['config']->set('preview.scenario_path', $path);

        $this->artisan('preview:scenario:make', [
            'name' => 'checkout-flow',
            '--route-user' => ['checkout.show:42'],
        ])
            ->expectsOutput('Scenario route user [checkout.show:42] must use route:id:model.')
            ->assertExitCode(1);
    }

    private function scenarioPath(): string
    {
        return sys_get_temp_dir().'/preview-tests/scenarios/'.spl_object_id($this);
    }
}

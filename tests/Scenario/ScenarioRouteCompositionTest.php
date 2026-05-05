<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Scenario;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Oxhq\Preview\Scenario\Scenario;
use Oxhq\Preview\Scenario\ScenarioRunner;
use Oxhq\Preview\Tests\TestCase;
use RuntimeException;

final class ScenarioRouteCompositionTest extends TestCase
{
    protected function tearDown(): void
    {
        ScenarioRouteCompositionListener::$calls = 0;

        parent::tearDown();
    }

    public function test_scenario_routes_execute_named_get_routes_through_signed_route_preview_with_parameters(): void
    {
        $path = $this->scenarioPath();
        $this->app['config']->set('preview.scenario_path', $path);

        Route::get('/scenario/accounts/{account}/dashboard', function (string $account) {
            return response()->json([
                'account' => $account,
                'preview_route' => request()->attributes->get('preview.route'),
            ]);
        })->name('preview.scenario.accounts.dashboard');

        $this->writeScenario($path, 'account-dashboard.php', <<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;

return new Scenario(
    name: 'account-dashboard',
    routes: ['preview.scenario.accounts.dashboard'],
    routeParameters: [
        'preview.scenario.accounts.dashboard' => ['account' => 'acme'],
    ],
);
PHP);

        $result = $this->app->make(ScenarioRunner::class)->replay('account-dashboard', 'exact');

        $this->assertCount(1, $result->routes);
        $this->assertSame('preview.scenario.accounts.dashboard', $result->routes[0]->preview->name);
        $this->assertSame(['account' => 'acme'], $result->routes[0]->preview->parameters);
        $this->assertStringContainsString('/__preview/route/preview.scenario.accounts.dashboard', $result->routes[0]->preview->url);
        $this->assertStringContainsString('signature=', $result->routes[0]->preview->url);
        $this->assertSame(200, $result->routes[0]->response->getStatusCode());
        $this->assertSame(
            '{"account":"acme","preview_route":"preview.scenario.accounts.dashboard"}',
            $result->routes[0]->response->getContent(),
        );
    }

    public function test_scenario_fakes_are_applied_to_route_previews(): void
    {
        if (! class_exists(Event::class) || ! method_exists(Event::class, 'fake')) {
            $this->markTestSkipped('Laravel Event fake is unavailable in this package install.');
        }

        $path = $this->scenarioPath();
        $this->app['config']->set('preview.scenario_path', $path);
        ScenarioRouteCompositionListener::$calls = 0;

        Event::listen(ScenarioRouteCompositionEvent::class, ScenarioRouteCompositionListener::class);

        Route::get('/scenario/faked-events', function (): string {
            event(new ScenarioRouteCompositionEvent());

            return 'event route executed';
        })->name('preview.scenario.faked-events');

        $this->writeScenario($path, 'faked-events.php', <<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;

return new Scenario(
    name: 'faked-events',
    routes: ['preview.scenario.faked-events'],
    fakes: ['events'],
);
PHP);

        $result = $this->app->make(ScenarioRunner::class)->replay('faked-events', 'exact');

        $this->assertCount(1, $result->routes);
        $this->assertSame(['events'], $result->routes[0]->preview->fakes);
        $this->assertSame(200, $result->routes[0]->response->getStatusCode());
        $this->assertSame('event route executed', $result->routes[0]->response->getContent());
        Event::assertDispatched(ScenarioRouteCompositionEvent::class);
        $this->assertSame(0, ScenarioRouteCompositionListener::$calls);
    }

    public function test_missing_route_parameters_fail_clearly(): void
    {
        $path = $this->scenarioPath();
        $this->app['config']->set('preview.scenario_path', $path);

        Route::get('/scenario/orders/{order}', fn (string $order): string => $order)
            ->name('preview.scenario.orders.show');

        $this->writeScenario($path, 'missing-route-param.php', <<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;

return new Scenario(
    name: 'missing-route-param',
    routes: ['preview.scenario.orders.show'],
);
PHP);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Route [preview.scenario.orders.show] requires missing parameters [order]. Pass them with --param=key=value.');

        $this->app->make(ScenarioRunner::class)->replay('missing-route-param', 'exact');
    }

    public function test_missing_routes_fail_clearly(): void
    {
        $path = $this->scenarioPath();
        $this->app['config']->set('preview.scenario_path', $path);

        $this->writeScenario($path, 'missing-route.php', <<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;

return new Scenario(
    name: 'missing-route',
    routes: ['preview.scenario.missing'],
);
PHP);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Route [preview.scenario.missing] was not found.');

        $this->app->make(ScenarioRunner::class)->replay('missing-route', 'exact');
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

final class ScenarioRouteCompositionEvent
{
}

final class ScenarioRouteCompositionListener
{
    public static int $calls = 0;

    public function handle(ScenarioRouteCompositionEvent $event): void
    {
        self::$calls++;
    }
}

<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Scenario;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
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

    public function test_route_context_is_applied_to_scenario_route_preview_execution(): void
    {
        $path = $this->scenarioPath();
        $this->app['config']->set('preview.scenario_path', $path);
        $this->useInMemoryDatabase();

        Route::get('/scenario/route-context', function () {
            DB::table('preview_scenario_writes')->insert(['message' => 'mutated']);

            return response()->json([
                'tenant' => session('tenant'),
                'mode' => request()->attributes->get('preview.session')['mode'] ?? null,
                'guard' => request()->attributes->get('preview.guard'),
                'readonly' => request()->attributes->get('preview.readonly_db'),
            ]);
        })->name('preview.scenario.route-context');

        $this->writeScenario($path, 'route-context.php', <<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;

return new Scenario(
    name: 'route-context',
    routes: ['preview.scenario.route-context'],
    routeContext: [
        'preview.scenario.route-context' => [
            'session' => [
                'tenant' => 'acme',
                'mode' => 'review',
            ],
            'guard' => 'client',
            'readonly_db' => true,
        ],
    ],
);
PHP);

        $result = $this->app->make(ScenarioRunner::class)->replay('route-context', 'exact');

        $this->assertSame([
            'tenant' => 'acme',
            'mode' => 'review',
        ], $result->routes[0]->preview->session);
        $this->assertSame('client', $result->routes[0]->preview->guard);
        $this->assertTrue($result->routes[0]->preview->readonlyDb);
        $this->assertSame(200, $result->routes[0]->response->getStatusCode());
        $this->assertSame(
            '{"tenant":"acme","mode":"review","guard":"client","readonly":true}',
            $result->routes[0]->response->getContent(),
        );
        $this->assertSame(0, DB::table('preview_scenario_writes')->count());
    }

    public function test_route_context_auth_and_fakes_are_merged_with_scenario_fakes(): void
    {
        $path = $this->scenarioPath();
        $this->app['config']->set('preview.scenario_path', $path);
        ScenarioRouteCompositionUser::$users = [
            '42' => new ScenarioRouteCompositionUser(['id' => '42', 'name' => 'Ada']),
        ];
        config()->set('auth.guards.preview', [
            'driver' => 'session',
            'provider' => 'users',
        ]);

        Route::get('/scenario/auth-context', fn () => response()->json([
            'request_user' => request()->user()?->getAuthIdentifier(),
            'request_guard_user' => request()->user('preview')?->getAuthIdentifier(),
        ]))->name('preview.scenario.auth-context');

        $this->writeScenario($path, 'auth-context.php', <<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;
use Oxhq\Preview\Tests\Scenario\ScenarioRouteCompositionUser;

return new Scenario(
    name: 'auth-context',
    routes: ['preview.scenario.auth-context'],
    fakes: ['events'],
    routeContext: [
        'preview.scenario.auth-context' => [
            'guard' => 'preview',
            'user_id' => '42',
            'user_model' => ScenarioRouteCompositionUser::class,
            'fakes' => ['mail', 'events'],
        ],
    ],
);
PHP);

        $result = $this->app->make(ScenarioRunner::class)->replay('auth-context', 'exact');

        $this->assertSame(['events', 'mail'], $result->routes[0]->preview->fakes);
        $this->assertSame('42', $result->routes[0]->preview->userId);
        $this->assertSame(ScenarioRouteCompositionUser::class, $result->routes[0]->preview->userModel);
        $this->assertSame(200, $result->routes[0]->response->getStatusCode());
        $this->assertSame(
            '{"request_user":"42","request_guard_user":"42"}',
            $result->routes[0]->response->getContent(),
        );
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

    private function useInMemoryDatabase(): void
    {
        $this->app['config']->set('database.default', 'preview_testing');
        $this->app['config']->set('database.connections.preview_testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        DB::purge('preview_testing');
        DB::connection('preview_testing')->getPdo();

        Schema::connection('preview_testing')->create('preview_scenario_writes', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('message');
        });
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

final class ScenarioRouteCompositionUser extends \Illuminate\Auth\GenericUser
{
    /**
     * @var array<string, self>
     */
    public static array $users = [];

    public static function find(string $id): ?\Illuminate\Contracts\Auth\Authenticatable
    {
        return self::$users[$id] ?? null;
    }
}

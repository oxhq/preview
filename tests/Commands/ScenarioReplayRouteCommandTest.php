<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Oxhq\Preview\Tests\TestCase;

final class ScenarioReplayRouteCommandTest extends TestCase
{
    public function test_preview_scenario_replay_executes_routes_and_prints_route_output(): void
    {
        $path = $this->scenarioPath();
        $this->app['config']->set('preview.scenario_path', $path);

        Route::get('/scenario-command/accounts/{account}', fn (string $account): string => "command-output:{$account}")
            ->name('preview.scenario-command.accounts.show');

        $this->writeScenario($path, 'command-account.php', <<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;

return new Scenario(
    name: 'command-account',
    routes: ['preview.scenario-command.accounts.show'],
    routeParameters: [
        'preview.scenario-command.accounts.show' => ['account' => 'acme'],
    ],
);
PHP);

        $this->artisan('preview:scenario:replay', [
            'scenario' => 'command-account',
            '--exact' => true,
        ])
            ->expectsOutput('Scenario replay ready for [command-account] using [exact].')
            ->expectsOutput('Captures: none')
            ->expectsOutput('Route: preview.scenario-command.accounts.show HTTP 200')
            ->expectsOutput('Route output: command-output:acme')
            ->expectsOutput('Summary: seed=0 captures=0 dispatches=0 routes=1')
            ->assertExitCode(0);
    }

    public function test_preview_scenario_replay_executes_routes_with_route_context(): void
    {
        $path = $this->scenarioPath();
        $this->app['config']->set('preview.scenario_path', $path);
        $this->useInMemoryDatabase();

        Route::get('/scenario-command/context', function (): string {
            DB::table('preview_command_writes')->insert(['message' => 'mutated']);

            return sprintf(
                'tenant:%s readonly:%s',
                session('tenant'),
                request()->attributes->get('preview.readonly_db') ? 'yes' : 'no',
            );
        })->name('preview.scenario-command.context');

        $this->writeScenario($path, 'command-context.php', <<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;

return new Scenario(
    name: 'command-context',
    routes: ['preview.scenario-command.context'],
    routeContext: [
        'preview.scenario-command.context' => [
            'session' => ['tenant' => 'acme'],
            'readonly_db' => true,
        ],
    ],
);
PHP);

        $this->artisan('preview:scenario:replay', [
            'scenario' => 'command-context',
            '--exact' => true,
        ])
            ->expectsOutput('Scenario replay ready for [command-context] using [exact].')
            ->expectsOutput('Route: preview.scenario-command.context HTTP 200')
            ->expectsOutput('Route output: tenant:acme readonly:yes')
            ->expectsOutput('Summary: seed=0 captures=0 dispatches=0 routes=1')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('preview_command_writes')->count());
    }

    public function test_preview_scenario_replay_reports_missing_route_parameters_clearly(): void
    {
        $path = $this->scenarioPath();
        $this->app['config']->set('preview.scenario_path', $path);

        Route::get('/scenario-command/orders/{order}', fn (string $order): string => $order)
            ->name('preview.scenario-command.orders.show');

        $this->writeScenario($path, 'missing-route-param.php', <<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;

return new Scenario(
    name: 'missing-route-param',
    routes: ['preview.scenario-command.orders.show'],
);
PHP);

        $this->artisan('preview:scenario:replay', [
            'scenario' => 'missing-route-param',
            '--exact' => true,
        ])
            ->expectsOutput('Route [preview.scenario-command.orders.show] requires missing parameters [order]. Pass them with --param=key=value.')
            ->assertExitCode(1);
    }

    public function test_preview_scenario_replay_reports_missing_routes_clearly(): void
    {
        $path = $this->scenarioPath();
        $this->app['config']->set('preview.scenario_path', $path);

        $this->writeScenario($path, 'missing-route.php', <<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;

return new Scenario(
    name: 'missing-route',
    routes: ['preview.scenario-command.missing'],
);
PHP);

        $this->artisan('preview:scenario:replay', [
            'scenario' => 'missing-route',
            '--exact' => true,
        ])
            ->expectsOutput('Route [preview.scenario-command.missing] was not found.')
            ->assertExitCode(1);
    }

    public function test_preview_scenario_replay_reports_failed_routes_with_summary(): void
    {
        $path = $this->scenarioPath();
        $this->app['config']->set('preview.scenario_path', $path);

        Route::get('/scenario-command/failing-route', fn () => response('route failed', 500))
            ->name('preview.scenario-command.failing');

        $this->writeScenario($path, 'failing-route.php', <<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;

return new Scenario(
    name: 'failing-route',
    routes: ['preview.scenario-command.failing'],
);
PHP);

        $this->artisan('preview:scenario:replay', [
            'scenario' => 'failing-route',
            '--exact' => true,
        ])
            ->expectsOutput('Scenario replay ready for [failing-route] using [exact].')
            ->expectsOutput('Captures: none')
            ->expectsOutput('Route: preview.scenario-command.failing HTTP 500')
            ->expectsOutput('Summary: seed=0 captures=0 dispatches=0 routes=1')
            ->expectsOutput('Scenario replay failed: route [preview.scenario-command.failing] returned HTTP 500.')
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

        Schema::connection('preview_testing')->create('preview_command_writes', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('message');
        });
    }
}

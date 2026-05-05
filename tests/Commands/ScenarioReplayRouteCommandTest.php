<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use Illuminate\Support\Facades\Route;
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
            ->assertExitCode(0);
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

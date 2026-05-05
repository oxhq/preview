<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Oxhq\Preview\Commands\ScenarioExportCommand;
use Oxhq\Preview\Tests\TestCase;

final class ScenarioExportCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ExportCommandRecordingSeeder::$runs = 0;
        ExportCommandRouteProbe::$hits = 0;
    }

    public function test_it_exports_safe_scenario_json_without_running_seed_routes_or_captures(): void
    {
        $path = $this->scenarioPath();
        $exportRoot = $this->exportRoot('scenario-export');
        $this->app['config']->set('preview.scenario_path', $path);
        $this->registerScenarioExportCommand();

        Route::get('/exports/probe/{order}', function (string $order): string {
            ExportCommandRouteProbe::$hits++;

            return $order;
        })->name('preview.exports.probe');

        $this->writeScenario($path, 'checkout.php', <<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;
use Oxhq\Preview\Tests\Commands\ExportCommandRecordingSeeder;

return new Scenario(
    name: 'checkout/export flow',
    seed: ExportCommandRecordingSeeder::class,
    routes: ['preview.exports.probe'],
    routeParameters: [
        'preview.exports.probe' => ['order' => 'ord_123'],
    ],
    routeContext: [
        'preview.exports.probe' => [
            'session' => ['tenant' => 'acme'],
            'guard' => 'web',
            'readonlyDb' => true,
            'fakes' => ['mail'],
        ],
    ],
    routeExpectations: [
        'preview.exports.probe' => [
            'status' => '200',
            'outputContains' => 'ord_123',
        ],
    ],
    captures: ['stripe.checkout.completed'],
    fakes: ['queue', 'mail'],
    notes: 'Happy path only',
);
PHP);

        $this->artisan('preview:scenario:export', [
            'scenario' => 'checkout/export flow',
            '--path' => $exportRoot,
        ])
            ->expectsOutput('Exported scenario [checkout/export flow].')
            ->expectsOutputToContain('scenario.json')
            ->assertExitCode(0);

        $exportPath = $exportRoot.DIRECTORY_SEPARATOR.'checkout-export-flow';
        $jsonPath = $exportPath.DIRECTORY_SEPARATOR.'scenario.json';

        $this->assertFileExists($jsonPath);
        $this->assertSame(0, ExportCommandRecordingSeeder::$runs);
        $this->assertSame(0, ExportCommandRouteProbe::$hits);

        $payload = json_decode((string) file_get_contents($jsonPath), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([
            'name' => 'checkout/export flow',
            'seed' => ExportCommandRecordingSeeder::class,
            'routes' => ['preview.exports.probe'],
            'routeParameters' => [
                'preview.exports.probe' => ['order' => 'ord_123'],
            ],
            'routeContext' => [
                'preview.exports.probe' => [
                    'session' => ['tenant' => 'acme'],
                    'guard' => 'web',
                    'readonly_db' => true,
                    'fakes' => ['mail'],
                ],
            ],
            'routeExpectations' => [
                'preview.exports.probe' => [
                    'status' => 200,
                    'output_contains' => 'ord_123',
                ],
            ],
            'captures' => ['stripe.checkout.completed'],
            'fakes' => ['queue', 'mail'],
            'notes' => 'Happy path only',
        ], $payload);
    }

    public function test_json_output_reports_export_details(): void
    {
        $path = $this->scenarioPath();
        $exportRoot = $this->exportRoot('json-output');
        $this->app['config']->set('preview.scenario_path', $path);
        $this->registerScenarioExportCommand();

        $this->writeScenario($path, 'billing.php', <<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;

return new Scenario(name: 'billing-flow');
PHP);

        $exitCode = Artisan::call('preview:scenario:export', [
            'scenario' => 'billing-flow',
            '--path' => $exportRoot,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('billing-flow', $payload['scenario']);
        $this->assertSame(['scenario.json'], $payload['files']);
        $this->assertSame(
            str_replace('\\', '/', $exportRoot.'/billing-flow'),
            str_replace('\\', '/', $payload['export_path']),
        );
        $this->assertFileExists($payload['export_path'].DIRECTORY_SEPARATOR.'scenario.json');
    }

    public function test_it_uses_a_safe_scenario_segment_for_export_directory(): void
    {
        $path = $this->scenarioPath();
        $exportRoot = $this->exportRoot('safe-segment');
        $this->app['config']->set('preview.scenario_path', $path);
        $this->registerScenarioExportCommand();

        $this->writeScenario($path, 'escape.php', <<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;

return new Scenario(name: '../escape');
PHP);

        $this->artisan('preview:scenario:export', [
            'scenario' => '../escape',
            '--path' => $exportRoot,
        ])->assertExitCode(0);

        $this->assertFileExists($exportRoot.DIRECTORY_SEPARATOR.'..-escape'.DIRECTORY_SEPARATOR.'scenario.json');
        $this->assertFileDoesNotExist(dirname($exportRoot).DIRECTORY_SEPARATOR.'escape'.DIRECTORY_SEPARATOR.'scenario.json');
    }

    public function test_it_fails_when_scenario_is_missing(): void
    {
        $this->app['config']->set('preview.scenario_path', $this->scenarioPath());
        $this->registerScenarioExportCommand();

        $this->artisan('preview:scenario:export', [
            'scenario' => 'missing-flow',
            '--path' => $this->exportRoot('missing'),
        ])
            ->expectsOutput('Scenario [missing-flow] was not found.')
            ->assertExitCode(1);
    }

    private function scenarioPath(): string
    {
        return sys_get_temp_dir().'/preview-tests/scenarios/'.spl_object_id($this);
    }

    private function exportRoot(string $name): string
    {
        return sys_get_temp_dir().'/preview-tests/exports/'.spl_object_id($this).'/'.$name;
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

    private function registerScenarioExportCommand(): void
    {
        $this->app->forgetInstance(\Oxhq\Preview\Scenario\ScenarioRepository::class);
        $this->app->make(Kernel::class)->registerCommand($this->app->make(ScenarioExportCommand::class));
    }
}

final class ExportCommandRecordingSeeder extends Seeder
{
    public static int $runs = 0;

    public function run(): void
    {
        self::$runs++;
    }
}

final class ExportCommandRouteProbe
{
    public static int $hits = 0;
}

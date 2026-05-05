<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Oxhq\Preview\Capture\CaptureRepository;
use Oxhq\Preview\Commands\ScenarioBundleCommand;
use Oxhq\Preview\Tests\TestCase;

final class ScenarioBundleCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_it_bundles_scenario_metadata_and_safe_capture_summaries_without_executing_anything(): void
    {
        $scenarioPath = sys_get_temp_dir().'/preview-tests/scenarios';
        $bundleRoot = sys_get_temp_dir().'/preview-tests/scenario-bundles';
        config()->set('preview.scenario_path', $scenarioPath);
        $this->registerCommand();

        $this->artisan('preview:capture', [
            'provider' => 'generic',
            '--path' => '/webhooks/orders',
            '--body' => '{"secret":"scenario-body"}',
            '--header' => ['X-Preview-Event: order.created', 'Authorization: Bearer scenario-secret'],
        ])->assertExitCode(0);

        $record = app(CaptureRepository::class)->all()[0];
        $this->writeScenario($scenarioPath, $record->id);

        $exitCode = Artisan::call('preview:scenario:bundle', [
            'scenario' => 'renewal',
            '--path' => $bundleRoot,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $output = Artisan::output();
        $details = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $bundlePath = $bundleRoot.DIRECTORY_SEPARATOR.'renewal';
        $bundle = json_decode((string) file_get_contents($bundlePath.DIRECTORY_SEPARATOR.'bundle.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('renewal', $details['scenario']);
        $this->assertSame(['bundle.json'], $details['files']);
        $this->assertFalse($details['raw_included']);
        $this->assertSame('renewal', $bundle['name']);
        $this->assertSame(['billing.portal'], $bundle['routes']);
        $this->assertSame([$record->id, 'missing-capture'], $bundle['captures']);
        $this->assertSame(['missing-capture'], $bundle['missing_captures']);
        $this->assertSame($record->id, $bundle['capture_summaries'][0]['id']);
        $this->assertSame('generic', $bundle['capture_summaries'][0]['provider']);
        $this->assertSame('order.created', $bundle['capture_summaries'][0]['event']);
        $this->assertSame(hash('sha256', '{"secret":"scenario-body"}'), $bundle['capture_summaries'][0]['body_sha256']);
        $this->assertStringNotContainsString('scenario-body', $output);
        $this->assertStringNotContainsString('scenario-secret', $output);
        $this->assertStringNotContainsString('scenario-body', json_encode($bundle, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('scenario-secret', json_encode($bundle, JSON_THROW_ON_ERROR));
    }

    public function test_it_fails_when_scenario_is_missing(): void
    {
        config()->set('preview.scenario_path', sys_get_temp_dir().'/preview-tests/missing-scenarios');
        $this->registerCommand();

        $this->artisan('preview:scenario:bundle', [
            'scenario' => 'missing',
            '--path' => sys_get_temp_dir().'/preview-tests/scenario-bundles',
        ])
            ->expectsOutput('Scenario [missing] was not found.')
            ->assertExitCode(1);
    }

    private function writeScenario(string $path, string $captureId): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0775, true);
        }

        file_put_contents($path.'/renewal.php', <<<PHP
<?php

use Oxhq\\Preview\\Scenario\\Scenario;

return new Scenario(
    name: 'renewal',
    seed: 'Database\\\\Seeders\\\\RenewalSeeder',
    routes: ['billing.portal'],
    routeParameters: ['billing.portal' => ['id' => '123']],
    routeExpectations: ['billing.portal' => ['status' => 200]],
    captures: ['{$captureId}', 'missing-capture'],
    fakes: ['queue'],
    notes: 'Safe bundle test.',
);
PHP);
    }

    private function registerCommand(): void
    {
        $this->app->forgetInstance(\Oxhq\Preview\Scenario\ScenarioRepository::class);
        $this->app->make(Kernel::class)->registerCommand($this->app->make(ScenarioBundleCommand::class));
    }
}

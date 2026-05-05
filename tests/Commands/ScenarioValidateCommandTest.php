<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Route;
use Oxhq\Preview\Capture\CaptureRepository;
use Oxhq\Preview\Capture\PreviewRequest;
use Oxhq\Preview\Providers\GenericProvider;
use Oxhq\Preview\Tests\TestCase;

final class ScenarioValidateCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ValidationCommandRecordingSeeder::$runs = 0;
        ValidationCommandRouteProbe::$hits = 0;
    }

    public function test_preview_scenario_validate_prints_ok_for_valid_local_references(): void
    {
        $path = $this->scenarioPath();
        $this->app['config']->set('preview.scenario_path', $path);
        $capture = $this->storeGenericCapture('/webhooks/orders');

        Route::get('/validation/orders/{order}', fn (string $order): string => $order)
            ->name('preview.validation.orders.show');

        $this->writeScenario($path, 'valid.php', sprintf(<<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;
use Oxhq\Preview\Tests\Commands\ValidationCommandRecordingSeeder;

return new Scenario(
    name: 'valid-flow',
    seed: ValidationCommandRecordingSeeder::class,
    routes: ['preview.validation.orders.show'],
    routeParameters: [
        'preview.validation.orders.show' => ['order' => 'ord_123'],
    ],
    routeExpectations: [
        'preview.validation.orders.show' => ['status' => 200],
    ],
    captures: ['%s'],
);
PHP, $capture->id));

        $this->artisan('preview:scenario:validate', ['scenario' => 'valid-flow'])
            ->expectsOutput('Scenario validation: valid-flow')
            ->expectsOutput('OK seed: '.ValidationCommandRecordingSeeder::class)
            ->expectsOutput("OK capture: {$capture->id}")
            ->expectsOutput('OK route: preview.validation.orders.show')
            ->expectsOutput('OK route expectation: preview.validation.orders.show')
            ->expectsOutput('Scenario valid.')
            ->assertExitCode(0);

        $this->assertSame(0, ValidationCommandRecordingSeeder::$runs);
        $this->assertSame(0, ValidationCommandRouteProbe::$hits);
    }

    public function test_preview_scenario_validate_reports_reference_failures_as_json(): void
    {
        $path = $this->scenarioPath();
        $this->app['config']->set('preview.scenario_path', $path);

        Route::get('/validation/accounts/{account}', fn (string $account): string => $account)
            ->name('preview.validation.accounts.show');

        $this->writeScenario($path, 'broken.php', <<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;

return new Scenario(
    name: 'broken-flow',
    seed: 'Oxhq\\Preview\\Tests\\Commands\\MissingValidationSeeder',
    routes: [
        'preview.validation.accounts.show',
        'preview.validation.missing',
    ],
    captures: ['missing-capture'],
    routeExpectations: [
        'preview.validation.not-in-scenario' => ['status' => 200],
    ],
);
PHP);

        [$exitCode, $output] = $this->runValidateJson([
            'scenario' => 'broken-flow',
            '--json' => true,
        ]);

        $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('broken-flow', $payload['scenario']);
        $this->assertFalse($payload['valid']);
        $this->assertContains('Scenario seed [Oxhq\\Preview\\Tests\\Commands\\MissingValidationSeeder] was not found.', $payload['errors']);
        $this->assertContains('Capture [missing-capture] was not found.', $payload['errors']);
        $this->assertContains('Route [preview.validation.accounts.show] requires missing parameters [account]. Pass them with --param=key=value.', $payload['errors']);
        $this->assertContains('Route [preview.validation.missing] was not found.', $payload['errors']);
        $this->assertContains('Route expectation [preview.validation.not-in-scenario] does not reference a route in scenario [broken-flow].', $payload['errors']);
        $this->assertSame([], $payload['warnings']);
    }

    public function test_preview_scenario_validate_does_not_execute_routes_or_seeds(): void
    {
        $path = $this->scenarioPath();
        $this->app['config']->set('preview.scenario_path', $path);

        Route::get('/validation/probe', function (): string {
            ValidationCommandRouteProbe::$hits++;

            return 'hit';
        })->name('preview.validation.probe');

        $this->writeScenario($path, 'side-effects.php', <<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;
use Oxhq\Preview\Tests\Commands\ValidationCommandRecordingSeeder;

return new Scenario(
    name: 'side-effects',
    seed: ValidationCommandRecordingSeeder::class,
    routes: ['preview.validation.probe'],
);
PHP);

        $this->artisan('preview:scenario:validate', ['scenario' => 'side-effects'])
            ->expectsOutput('Scenario valid.')
            ->assertExitCode(0);

        $this->assertSame(0, ValidationCommandRecordingSeeder::$runs);
        $this->assertSame(0, ValidationCommandRouteProbe::$hits);
    }

    public function test_preview_scenario_validate_json_reports_missing_scenario(): void
    {
        $this->app['config']->set('preview.scenario_path', $this->scenarioPath());

        [$exitCode, $output] = $this->runValidateJson([
            'scenario' => 'missing-flow',
            '--json' => true,
        ]);

        $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('missing-flow', $payload['scenario']);
        $this->assertFalse($payload['valid']);
        $this->assertSame(['Scenario [missing-flow] was not found.'], $payload['errors']);
        $this->assertSame([], $payload['warnings']);
    }

    private function storeGenericCapture(string $path): object
    {
        return $this->app->make(CaptureRepository::class)->store(
            PreviewRequest::make(
                provider: 'generic',
                method: 'POST',
                path: $path,
                query: [],
                headers: ['X-Preview-Event' => 'scenario.validate'],
                rawBody: '{"ok":true}',
            ),
            new GenericProvider(),
        );
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

    /**
     * @param array<string, mixed> $parameters
     * @return array{0: int, 1: string}
     */
    private function runValidateJson(array $parameters): array
    {
        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        $exitCode = $this->app->make(Kernel::class)->call('preview:scenario:validate', $parameters, $output);

        return [$exitCode, $output->fetch()];
    }
}

final class ValidationCommandRecordingSeeder extends Seeder
{
    public static int $runs = 0;

    public function run(): void
    {
        self::$runs++;
    }
}

final class ValidationCommandRouteProbe
{
    public static int $hits = 0;
}

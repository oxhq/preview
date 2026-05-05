<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Oxhq\Preview\Commands\RouteExportCommand;
use Oxhq\Preview\Tests\TestCase;
use RuntimeException;

final class RouteExportCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RouteExportCommandProbe::$hits = 0;
        $this->app->make(Kernel::class)->registerCommand($this->app->make(RouteExportCommand::class));
    }

    public function test_it_exports_one_named_route_without_executing_the_route_action(): void
    {
        $exportRoot = $this->exportRoot('single-route');

        Route::domain('admin.example.test')
            ->middleware('web')
            ->get('/accounts/{account}', function (string $account): string {
                RouteExportCommandProbe::$hits++;

                throw new RuntimeException("route action executed for {$account}");
            })
            ->middleware('auth')
            ->name('preview.exports.accounts.show');

        $this->artisan('preview:route:export', [
            'route' => 'preview.exports.accounts.show',
            '--path' => $exportRoot,
        ])
            ->expectsOutput('Exported route [preview.exports.accounts.show].')
            ->expectsOutputToContain('route.json')
            ->assertExitCode(0);

        $jsonPath = $exportRoot.DIRECTORY_SEPARATOR.'preview.exports.accounts.show'.DIRECTORY_SEPARATOR.'route.json';

        $this->assertSame(0, RouteExportCommandProbe::$hits);
        $this->assertFileExists($jsonPath);

        $payload = json_decode((string) file_get_contents($jsonPath), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([
            'name' => 'preview.exports.accounts.show',
            'methods' => ['GET', 'HEAD'],
            'uri' => 'accounts/{account}',
            'domain' => 'admin.example.test',
            'action' => 'Closure',
            'middleware' => ['web', 'auth'],
            'risk' => 'read',
            'safety_hint' => 'Read-only route metadata; exporting does not execute the route.',
        ], $payload);
    }

    public function test_it_exports_all_named_routes_and_skips_unnamed_routes(): void
    {
        $exportRoot = $this->exportRoot('all-routes');

        Route::get('/exports/alpha', fn (): string => 'alpha')
            ->name('preview.exports.alpha');

        Route::post('/exports/beta', function (): string {
            RouteExportCommandProbe::$hits++;

            return 'beta';
        })
            ->middleware(['web', 'auth'])
            ->name('preview.exports.beta');

        Route::get('/exports/unnamed', function (): string {
            RouteExportCommandProbe::$hits++;

            return 'unnamed';
        });

        $exitCode = Artisan::call('preview:route:export', [
            '--path' => $exportRoot,
        ], $output = new \Symfony\Component\Console\Output\BufferedOutput());
        $contents = $output->fetch();

        $this->assertSame(0, $exitCode, $contents);
        $this->assertStringContainsString('Exported', $contents);

        $alphaPath = $exportRoot.DIRECTORY_SEPARATOR.'preview.exports.alpha'.DIRECTORY_SEPARATOR.'route.json';
        $betaPath = $exportRoot.DIRECTORY_SEPARATOR.'preview.exports.beta'.DIRECTORY_SEPARATOR.'route.json';

        $this->assertSame(0, RouteExportCommandProbe::$hits);
        $this->assertFileExists($alphaPath);
        $this->assertFileExists($betaPath);
        $this->assertFileDoesNotExist($exportRoot.DIRECTORY_SEPARATOR.'exports-unnamed'.DIRECTORY_SEPARATOR.'route.json');

        $beta = json_decode((string) file_get_contents($betaPath), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('write', $beta['risk']);
        $this->assertSame('Write methods can run application side effects if previewed.', $beta['safety_hint']);
        $this->assertSame(['POST'], $beta['methods']);
        $this->assertSame(['web', 'auth'], $beta['middleware']);
    }

    public function test_json_output_reports_export_details(): void
    {
        $exportRoot = $this->exportRoot('json-output');

        Route::get('/exports/json', fn (): string => 'json')
            ->name('preview.exports.json');

        $exitCode = Artisan::call('preview:route:export', [
            'route' => 'preview.exports.json',
            '--path' => $exportRoot,
            '--json' => true,
        ], $output = new \Symfony\Component\Console\Output\BufferedOutput());
        $contents = $output->fetch();

        $this->assertSame(0, $exitCode, $contents);
        $this->assertJson($contents, $contents);

        $payload = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['preview.exports.json'], $payload['routes']);
        $this->assertSame(['preview.exports.json/route.json'], str_replace('\\', '/', $payload['files']));
        $this->assertSame(
            str_replace('\\', '/', $exportRoot),
            str_replace('\\', '/', $payload['export_root']),
        );
    }

    public function test_it_uses_a_safe_route_segment_for_export_directory(): void
    {
        $exportRoot = $this->exportRoot('safe-segment');

        Route::get('/exports/escape', fn (): string => 'escape')
            ->name('../escape');

        $this->artisan('preview:route:export', [
            'route' => '../escape',
            '--path' => $exportRoot,
        ])->assertExitCode(0);

        $this->assertFileExists($exportRoot.DIRECTORY_SEPARATOR.'..-escape'.DIRECTORY_SEPARATOR.'route.json');
        $this->assertFileDoesNotExist(dirname($exportRoot).DIRECTORY_SEPARATOR.'escape'.DIRECTORY_SEPARATOR.'route.json');
    }

    public function test_it_fails_when_route_is_missing(): void
    {
        $this->artisan('preview:route:export', [
            'route' => 'missing.route',
            '--path' => $this->exportRoot('missing-route'),
        ])
            ->expectsOutput('Route [missing.route] was not found.')
            ->assertExitCode(1);
    }

    private function exportRoot(string $name): string
    {
        return sys_get_temp_dir().'/preview-tests/exports/'.spl_object_id($this).'/'.$name;
    }
}

final class RouteExportCommandProbe
{
    public static int $hits = 0;
}

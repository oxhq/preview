<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Oxhq\Preview\Commands\RouteDoctorCommand;
use Oxhq\Preview\Tests\TestCase;
use RuntimeException;

final class RouteDoctorCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(Kernel::class)->registerCommand(new RouteDoctorCommand());
    }

    public function test_it_reports_route_preview_readiness_without_executing_routes(): void
    {
        Route::setRoutes(new RouteCollection());
        config()->set('preview.route_preview.enabled', true);
        config()->set('preview.route_preview.path', '/internal/preview/{route}');
        config()->set('app.key', 'base64:test-key');

        Route::get('/__preview/route/{route}', fn (): string => 'access')
            ->name('preview.route.access');

        Route::get('/accounts/{account}', function (): string {
            throw new RuntimeException('route action executed');
        })->name('app.accounts.show');

        Route::post('/orders', function (): string {
            throw new RuntimeException('route action executed');
        })->name('app.orders.store');

        $exitCode = Artisan::call('preview:route:doctor');
        $output = Artisan::output();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Route preview diagnostics:', $output);
        self::assertStringContainsString(' - Config enabled: yes', $output);
        self::assertStringContainsString(' - Preview path: /internal/preview/{route}', $output);
        self::assertStringContainsString(' - App key present: yes', $output);
        self::assertStringContainsString(' - Preview access route: yes', $output);
        self::assertStringContainsString(' - Named routes: 3', $output);
        self::assertStringContainsString(' - Read routes: 2', $output);
        self::assertStringContainsString(' - Write routes: 1', $output);
        self::assertStringContainsString(' - Supported fakes: queue, mail, http, events', $output);
        self::assertStringContainsString('Warnings:', $output);
        self::assertStringContainsString(' - write routes exist and require explicit opt-in/isolation', $output);
        self::assertStringNotContainsString('Full isolation', $output);
        self::assertStringNotContainsString('Preview URL:', $output);
    }

    public function test_it_outputs_machine_readable_json_with_issues_and_warnings(): void
    {
        Route::setRoutes(new RouteCollection());
        config()->set('preview.route_preview.enabled', false);
        config()->set('preview.route_preview.path', '/disabled-preview/{route}');
        config()->set('app.key', null);

        Route::get('/reports', function (): string {
            throw new RuntimeException('route action executed');
        })->name('app.reports.index');

        Route::delete('/orders/{order}', function (): string {
            throw new RuntimeException('route action executed');
        })->name('app.orders.destroy');

        $exitCode = Artisan::call('preview:route:doctor', ['--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        self::assertSame(0, $exitCode);
        self::assertSame([
            'config_enabled' => false,
            'preview_path' => '/disabled-preview/{route}',
            'app_key_present' => false,
            'preview_access_route_exists' => false,
            'named_route_count' => 2,
            'write_route_count' => 1,
            'read_route_count' => 1,
            'supported_fakes' => ['queue', 'mail', 'http', 'events'],
            'issues' => [
                'route preview disabled',
                'missing APP_KEY/config app.key',
                'preview access route missing',
            ],
            'warnings' => [
                'write routes exist and require explicit opt-in/isolation',
            ],
        ], $payload);
    }
}

<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Oxhq\Preview\Tests\TestCase;
use RuntimeException;

final class RouteListCommandTest extends TestCase
{
    public function test_it_lists_named_routes_without_executing_routes_or_signing_preview_urls(): void
    {
        Route::get('/accounts/{account}', function (): string {
            throw new RuntimeException('route action executed');
        })
            ->middleware(['web', 'auth'])
            ->name('app.accounts.show');

        Route::post('/orders', function (): string {
            throw new RuntimeException('route action executed');
        })
            ->middleware('web')
            ->name('app.orders.store');

        $exitCode = Artisan::call('preview:route:list', [
            '--filter' => 'app.',
        ]);

        $output = Artisan::output();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Preview routes:', $output);
        self::assertStringContainsString('app.accounts.show | GET, HEAD | accounts/{account} | middleware: 2 [web, auth] | safe: read-only route', $output);
        self::assertStringContainsString('app.orders.store | POST | orders | middleware: 1 [web] | risk: write method; do not preview without side-effect isolation', $output);
        self::assertStringNotContainsString('Preview URL:', $output);
        self::assertStringNotContainsString('signature=', $output);
    }

    public function test_it_outputs_filtered_machine_readable_json(): void
    {
        Route::get('/accounts', fn (): string => 'ok')
            ->middleware('web')
            ->name('app.accounts.index');

        Route::delete('/orders/{order}', fn (): string => 'deleted')
            ->middleware(['web', 'auth'])
            ->name('app.orders.destroy');

        Route::get('/health', fn (): string => 'ok')
            ->name('system.health');

        $exitCode = Artisan::call('preview:route:list', [
            '--filter' => 'orders',
            '--json' => true,
        ]);

        $payload = json_decode(trim(Artisan::output()), true);

        self::assertSame(0, $exitCode);
        self::assertSame([
            [
                'name' => 'app.orders.destroy',
                'methods' => ['DELETE'],
                'uri' => 'orders/{order}',
                'middleware_count' => 2,
                'middleware' => ['web', 'auth'],
                'risk' => 'write',
                'safety_hint' => 'Write methods can run application side effects if previewed.',
            ],
        ], $payload);
    }
}

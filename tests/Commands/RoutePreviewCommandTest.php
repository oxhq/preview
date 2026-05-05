<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Oxhq\Preview\Tests\TestCase;

final class RoutePreviewCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_preview_route_command_prints_safe_route_preview_details(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-03 12:00:00', 'UTC'));

        Route::get('/accounts/{account}/dashboard', fn (string $account): string => $account)
            ->middleware(['web', 'auth'])
            ->name('preview.accounts.dashboard');

        $this->artisan('preview:route', [
            'route' => 'preview.accounts.dashboard',
            '--ttl' => '30m',
            '--param' => ['account=acme'],
            '--readonly-db' => true,
            '--guard' => 'client',
        ])
            ->expectsOutput('Route: preview.accounts.dashboard')
            ->expectsOutput('URI: accounts/{account}/dashboard')
            ->expectsOutput('Methods: GET, HEAD')
            ->expectsOutput('Middleware: web, auth')
            ->expectsOutput('Action: Closure')
            ->expectsOutput('Parameters: account=acme')
            ->expectsOutput('Guard: client')
            ->expectsOutput('Readonly DB: requested')
            ->expectsOutput('Expiration: 2026-05-03 12:30:00 UTC')
            ->expectsOutputToContain('Preview URL:')
            ->expectsOutputToContain('/__preview/route/preview.accounts.dashboard')
            ->expectsOutputToContain('_preview_params=')
            ->expectsOutputToContain('signature=')
            ->expectsOutput('Warnings:')
            ->assertExitCode(0);
    }

    public function test_preview_route_command_rejects_missing_routes(): void
    {
        $this->artisan('preview:route', [
            'route' => 'missing.route',
            '--ttl' => '30m',
        ])
            ->expectsOutput('Route [missing.route] was not found.')
            ->assertExitCode(1);
    }

    public function test_preview_route_command_blocks_write_routes_unless_allow_write_is_passed(): void
    {
        Route::post('/orders', fn (): string => 'created')
            ->name('preview.orders.store');

        $this->artisan('preview:route', [
            'route' => 'preview.orders.store',
            '--ttl' => '30m',
        ])
            ->expectsOutput('Route [preview.orders.store] includes non-read methods [POST]. Pass --allow-write to preview it.')
            ->assertExitCode(1);
    }

    public function test_preview_route_command_prints_user_context_when_requested(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-03 12:00:00', 'UTC'));

        Route::get('/auth-context', fn (): string => 'ok')
            ->name('preview.auth-context');

        $this->artisan('preview:route', [
            'route' => 'preview.auth-context',
            '--ttl' => '30m',
            '--user-id' => '42',
            '--user-model' => RoutePreviewCommandTestUser::class,
        ])
            ->expectsOutput('Route: preview.auth-context')
            ->expectsOutput('User: 42 via '.RoutePreviewCommandTestUser::class)
            ->expectsOutput('Warnings:')
            ->expectsOutput(' - User [42] will be resolved through ['.RoutePreviewCommandTestUser::class.'] during proxied execution; application middleware and policies still decide authorization.')
            ->assertExitCode(0);
    }

    public function test_preview_route_command_requires_user_model_for_user_context(): void
    {
        Route::get('/auth-context', fn (): string => 'ok')
            ->name('preview.auth-context');

        $this->artisan('preview:route', [
            'route' => 'preview.auth-context',
            '--ttl' => '30m',
            '--user-id' => '42',
        ])
            ->expectsOutput('Pass --user-model or configure preview.route_preview.user_model to use --user-id.')
            ->assertExitCode(1);
    }

    public function test_preview_route_command_rejects_missing_route_parameters(): void
    {
        Route::get('/accounts/{account}/dashboard', fn (string $account): string => $account)
            ->name('preview.accounts.dashboard');

        $this->artisan('preview:route', [
            'route' => 'preview.accounts.dashboard',
            '--ttl' => '30m',
        ])
            ->expectsOutput('Route [preview.accounts.dashboard] requires missing parameters [account]. Pass them with --param=key=value.')
            ->assertExitCode(1);
    }

    public function test_preview_route_command_allows_write_routes_with_warning_when_requested(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-03 12:00:00', 'UTC'));

        Route::post('/orders', fn (): string => 'created')
            ->middleware('web')
            ->name('preview.orders.store');

        $this->artisan('preview:route', [
            'route' => 'preview.orders.store',
            '--ttl' => '2h',
            '--allow-write' => true,
        ])
            ->expectsOutput('Route: preview.orders.store')
            ->expectsOutput('URI: orders')
            ->expectsOutput('Methods: POST')
            ->expectsOutput('Middleware: web')
            ->expectsOutput('Expiration: 2026-05-03 14:00:00 UTC')
            ->expectsOutputToContain('Preview URL:')
            ->expectsOutput('Warnings:')
            ->expectsOutput(' - Route includes non-read methods [POST]. Database rollback does not protect queues, mail, cache, filesystem writes, events, or external HTTP calls.')
            ->assertExitCode(0);
    }
}

final class RoutePreviewCommandTestUser
{
}

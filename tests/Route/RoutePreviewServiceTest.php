<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Route;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Oxhq\Preview\Route\RoutePreviewService;
use Oxhq\Preview\Tests\TestCase;
use RuntimeException;

final class RoutePreviewServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_builds_a_signed_preview_for_a_named_read_route_with_context(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-03 12:00:00', 'UTC'));

        Route::get('/accounts/{account}/dashboard', fn (string $account): string => $account)
            ->middleware(['web', 'auth'])
            ->name('preview.accounts.dashboard');

        $preview = app(RoutePreviewService::class)->preview(
            routeName: 'preview.accounts.dashboard',
            ttl: '30m',
            parameters: ['account' => 'acme'],
            readonlyDb: true,
            guard: 'client',
        );

        $this->assertSame('preview.accounts.dashboard', $preview->name);
        $this->assertSame('accounts/{account}/dashboard', $preview->uri);
        $this->assertSame('Closure', $preview->action);
        $this->assertNull($preview->domain);
        $this->assertSame(['GET', 'HEAD'], $preview->methods);
        $this->assertSame(['web', 'auth'], $preview->middleware);
        $this->assertSame('2026-05-03 12:30:00', $preview->expiresAt->format('Y-m-d H:i:s'));
        $this->assertSame(['account' => 'acme'], $preview->parameters);
        $this->assertTrue($preview->readonlyDb);
        $this->assertSame('client', $preview->guard);

        $this->assertStringContainsString('/__preview/route/preview.accounts.dashboard', $preview->url);
        $this->assertStringContainsString('signature=', $preview->url);
        $this->assertStringContainsString('expires=', $preview->url);
        $this->assertStringContainsString('_preview_params=', $preview->url);
        $this->assertStringContainsString('_preview_readonly_db=1', $preview->url);
        $this->assertStringContainsString('_preview_guard=client', $preview->url);
    }

    public function test_it_parses_hour_ttls_into_expirations(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-03 12:00:00', 'UTC'));

        Route::get('/billing/portal', fn (): string => 'ok')
            ->name('billing.portal');

        $preview = app(RoutePreviewService::class)->preview(
            routeName: 'billing.portal',
            ttl: '2h',
        );

        $this->assertSame('2026-05-03 14:00:00', $preview->expiresAt->format('Y-m-d H:i:s'));
    }

    public function test_signed_preview_url_redirects_to_the_named_route_with_parameters(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-03 12:00:00', 'UTC'));

        Route::get('/accounts/{account}/dashboard', fn (string $account): string => $account)
            ->name('preview.accounts.dashboard');

        $preview = app(RoutePreviewService::class)->preview(
            routeName: 'preview.accounts.dashboard',
            ttl: '30m',
            parameters: ['account' => 'acme'],
        );

        $this->get($preview->url)
            ->assertRedirect(route('preview.accounts.dashboard', ['account' => 'acme']));
    }

    public function test_it_rejects_missing_named_routes(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Route [missing.route] was not found.');

        app(RoutePreviewService::class)->preview(
            routeName: 'missing.route',
            ttl: '30m',
        );
    }

    public function test_it_rejects_missing_required_route_parameters(): void
    {
        Route::get('/accounts/{account}/dashboard', fn (string $account): string => $account)
            ->name('preview.accounts.dashboard');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Route [preview.accounts.dashboard] requires missing parameters [account]. Pass them with --param=key=value.');

        app(RoutePreviewService::class)->preview(
            routeName: 'preview.accounts.dashboard',
            ttl: '30m',
        );
    }

    public function test_it_blocks_write_routes_by_default_with_a_clear_error(): void
    {
        Route::post('/orders', fn (): string => 'created')
            ->name('preview.orders.store');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Route [preview.orders.store] includes non-read methods [POST]. Pass allowWrite=true to preview it.');

        app(RoutePreviewService::class)->preview(
            routeName: 'preview.orders.store',
            ttl: '30m',
        );
    }

    public function test_it_allows_write_routes_with_explicit_opt_in_and_returns_warnings(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-03 12:00:00', 'UTC'));

        Route::post('/orders', fn (): string => 'created')
            ->middleware('web')
            ->name('preview.orders.store');

        $preview = app(RoutePreviewService::class)->preview(
            routeName: 'preview.orders.store',
            ttl: '30m',
            allowWrite: true,
        );

        $this->assertSame('preview.orders.store', $preview->name);
        $this->assertSame(['POST'], $preview->methods);
        $this->assertStringContainsString('/__preview/route/preview.orders.store', $preview->url);
        $this->assertContains('Route includes non-read methods [POST]. Database rollback does not protect queues, mail, cache, filesystem writes, events, or external HTTP calls.', $preview->warnings);
    }
}

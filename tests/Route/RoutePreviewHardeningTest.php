<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Route;

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Oxhq\Preview\Route\RoutePreviewService;
use Oxhq\Preview\Tests\TestCase;
use RuntimeException;

final class RoutePreviewHardeningTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        RoutePreviewAuthenticatableUser::$users = [];

        parent::tearDown();
    }

    public function test_optional_route_parameters_do_not_require_param_flags(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-04 12:00:00', 'UTC'));

        Route::get('/reports/{section?}', fn (?string $section = null): string => $section ?? 'summary')
            ->name('preview.reports.optional');

        $preview = app(RoutePreviewService::class)->preview(
            routeName: 'preview.reports.optional',
            ttl: '30m',
        );

        $this->assertSame([], $preview->parameters);
        $this->assertStringContainsString('/__preview/route/preview.reports.optional', $preview->url);
    }

    public function test_domain_route_parameters_are_required(): void
    {
        Route::domain('{tenant}.example.test')
            ->get('/dashboard', fn (string $tenant): string => $tenant)
            ->name('preview.tenant.dashboard');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Route [preview.tenant.dashboard] requires missing parameters [tenant]. Pass them with --param=key=value.');

        app(RoutePreviewService::class)->preview(
            routeName: 'preview.tenant.dashboard',
            ttl: '30m',
        );
    }

    public function test_expired_signed_preview_links_are_rejected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-04 12:00:00', 'UTC'));

        Route::get('/signed-preview/ok', fn (): string => 'ok')
            ->name('preview.signed.ok');

        $preview = app(RoutePreviewService::class)->preview(
            routeName: 'preview.signed.ok',
            ttl: '30m',
        );

        Carbon::setTestNow(Carbon::parse('2026-05-04 12:31:00', 'UTC'));

        $this->get($preview->url)->assertForbidden();
    }

    public function test_tampered_signed_preview_links_are_rejected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-04 12:00:00', 'UTC'));

        Route::get('/signed-preview/tamper', fn (): string => 'ok')
            ->name('preview.signed.tamper');

        $preview = app(RoutePreviewService::class)->preview(
            routeName: 'preview.signed.tamper',
            ttl: '30m',
        );

        $separator = str_contains($preview->url, '?') ? '&' : '?';

        $this->get($preview->url.$separator.'tampered=1')->assertForbidden();
    }

    public function test_signed_preview_link_proxies_named_get_route_and_returns_target_response(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-04 12:00:00', 'UTC'));

        Route::get('/invoices/{invoice}', fn (string $invoice) => response("invoice:{$invoice}", 202))
            ->name('preview.invoices.show');

        $preview = app(RoutePreviewService::class)->preview(
            routeName: 'preview.invoices.show',
            parameters: ['invoice' => 'inv_123'],
            ttl: '30m',
        );

        $this->get($preview->url)
            ->assertStatus(202)
            ->assertSee('invoice:inv_123');
    }

    public function test_signed_preview_link_carries_session_context_into_proxied_request(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-04 12:00:00', 'UTC'));

        Route::get('/session-context', fn () => response()->json([
            'tenant' => session('tenant'),
            'mode' => request()->attributes->get('preview.session')['mode'] ?? null,
            'guard' => request()->attributes->get('preview.guard'),
        ]))->name('preview.session-context');

        $preview = app(RoutePreviewService::class)->preview(
            routeName: 'preview.session-context',
            ttl: '30m',
            guard: 'client',
            session: [
                'tenant' => 'acme',
                'mode' => 'review',
            ],
        );

        $this->assertSame([
            'tenant' => 'acme',
            'mode' => 'review',
        ], $preview->session);
        $this->assertStringContainsString('_preview_session=', $preview->url);

        $this->get($preview->url)
            ->assertOk()
            ->assertJson([
                'tenant' => 'acme',
                'mode' => 'review',
                'guard' => 'client',
            ]);
    }

    public function test_preview_route_command_prints_session_context_without_claiming_impersonation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-04 12:00:00', 'UTC'));

        Route::get('/session-audit', fn (): string => 'ok')
            ->name('preview.session-audit');

        $this->artisan('preview:route', [
            'route' => 'preview.session-audit',
            '--ttl' => '30m',
            '--guard' => 'client',
            '--session' => ['tenant=acme', 'mode=review'],
        ])
            ->expectsOutput('Guard: client')
            ->expectsOutput('Session: tenant=acme, mode=review')
            ->expectsOutput('Warnings:')
            ->expectsOutput(' - Guard [client] is recorded on the preview link; it does not bypass application authorization.')
            ->expectsOutput(' - Session context is attached to the proxied request; it does not authenticate a user or bypass authorization.')
            ->doesntExpectOutput('Impersonation: enabled')
            ->assertExitCode(0);
    }

    public function test_signed_preview_link_can_resolve_app_specific_user_context_for_proxied_request(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-04 12:00:00', 'UTC'));
        RoutePreviewAuthenticatableUser::$users = [
            '42' => new RoutePreviewAuthenticatableUser(['id' => '42', 'name' => 'Ada']),
        ];
        config()->set('auth.guards.preview', [
            'driver' => 'session',
            'provider' => 'users',
        ]);

        Route::get('/auth-context', fn () => response()->json([
            'request_user' => request()->user()?->getAuthIdentifier(),
            'request_guard_user' => request()->user('preview')?->getAuthIdentifier(),
            'auth_user' => Auth::user()?->getAuthIdentifier(),
            'guard' => Auth::getDefaultDriver(),
        ]))->name('preview.auth-context');

        $preview = app(RoutePreviewService::class)->preview(
            routeName: 'preview.auth-context',
            ttl: '30m',
            guard: 'preview',
            userId: '42',
            userModel: RoutePreviewAuthenticatableUser::class,
        );

        $this->assertSame('42', $preview->userId);
        $this->assertSame('preview', $preview->guard);
        $this->assertSame(RoutePreviewAuthenticatableUser::class, $preview->userModel);
        $this->assertStringContainsString('_preview_user_id=42', $preview->url);
        $this->assertStringContainsString('_preview_user_model=', $preview->url);

        $this->get($preview->url)
            ->assertOk()
            ->assertJson([
                'request_user' => '42',
                'request_guard_user' => '42',
                'auth_user' => '42',
                'guard' => 'preview',
            ]);

        $this->assertNull(Auth::user());
    }

    public function test_signed_preview_link_rejects_unresolvable_user_context(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-04 12:00:00', 'UTC'));
        RoutePreviewAuthenticatableUser::$users = [];

        Route::get('/missing-auth-context', fn (): string => 'should not run')
            ->name('preview.missing-auth-context');

        $preview = app(RoutePreviewService::class)->preview(
            routeName: 'preview.missing-auth-context',
            ttl: '30m',
            userId: '404',
            userModel: RoutePreviewAuthenticatableUser::class,
        );

        $this->get($preview->url)->assertForbidden();
    }

    public function test_readonly_db_rolls_back_database_writes_in_proxied_request(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-04 12:00:00', 'UTC'));
        $this->useInMemoryDatabase();

        Route::get('/db-writes', function (): string {
            DB::table('preview_writes')->insert(['message' => 'mutated']);

            return 'write attempted';
        })->name('preview.db-writes');

        $preview = app(RoutePreviewService::class)->preview(
            routeName: 'preview.db-writes',
            ttl: '30m',
            readonlyDb: true,
        );

        $this->get($preview->url)
            ->assertOk()
            ->assertSee('write attempted');

        $this->assertSame(0, DB::table('preview_writes')->count());
    }

    public function test_explicit_fake_flags_mark_output_without_claiming_full_isolation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-04 12:00:00', 'UTC'));

        Route::get('/side-effects', fn (): string => 'side effects')
            ->name('preview.side-effects');

        $this->artisan('preview:route', [
            'route' => 'preview.side-effects',
            '--ttl' => '30m',
            '--fake-queue' => true,
            '--fake-mail' => true,
            '--fake-http' => true,
            '--fake-events' => true,
        ])
            ->expectsOutput('Route: preview.side-effects')
            ->expectsOutput('Fakes: queue, mail, http, events')
            ->expectsOutput('Warnings:')
            ->expectsOutput(' - Preview will request fake [queue] during proxied execution.')
            ->expectsOutput(' - Preview will request fake [mail] during proxied execution.')
            ->expectsOutput(' - Preview will request fake [http] during proxied execution.')
            ->expectsOutput(' - Preview will request fake [events] during proxied execution.')
            ->doesntExpectOutput('Full isolation: enabled')
            ->assertExitCode(0);
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

        Schema::connection('preview_testing')->create('preview_writes', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('message');
        });
    }
}

final class RoutePreviewAuthenticatableUser extends GenericUser
{
    /**
     * @var array<string, self>
     */
    public static array $users = [];

    public static function find(string $id): ?Authenticatable
    {
        return self::$users[$id] ?? null;
    }
}

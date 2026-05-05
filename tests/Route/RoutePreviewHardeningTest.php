<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Route;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
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

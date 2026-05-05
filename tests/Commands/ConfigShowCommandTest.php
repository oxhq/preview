<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Oxhq\Preview\Commands\ConfigShowCommand;
use Oxhq\Preview\Core\Transport\CloudflareTunnelTransport;
use Oxhq\Preview\Core\Transport\NgrokTunnelTransport;
use Oxhq\Preview\Core\Transport\StripeCliTunnelTransport;
use Oxhq\Preview\Providers\GenericHmacProvider;
use Oxhq\Preview\Providers\GitHubProvider;
use Oxhq\Preview\Providers\ShopifyProvider;
use Oxhq\Preview\Providers\StripeProvider;
use Oxhq\Preview\Tests\TestCase;

final class ConfigShowCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(Kernel::class)->registerCommand($this->app->make(ConfigShowCommand::class));
    }

    public function test_it_outputs_stable_redacted_json_configuration_summary(): void
    {
        config()->set('preview.storage_path', '/tmp/preview/captures');
        config()->set('preview.fixture_path', '/tmp/preview/fixtures');
        config()->set('preview.test_path', '/tmp/preview/tests');
        config()->set('preview.scenario_path', '/tmp/preview/scenarios');
        config()->set('preview.http_capture.enabled', true);
        config()->set('preview.http_capture.path', '/preview/capture/{provider}');
        config()->set('preview.route_preview.enabled', false);
        config()->set('preview.route_preview.path', '/preview/route/{route}');
        config()->set('preview.live_enabled', true);
        config()->set('preview.redact_headers', ['authorization', 'x-secret-token']);
        config()->set('preview.providers', [
            'hmac' => GenericHmacProvider::class,
            'github' => GitHubProvider::class,
            'shopify' => ShopifyProvider::class,
            'stripe' => StripeProvider::class,
        ]);
        config()->set('preview.transports', [
            'cloudflare' => CloudflareTunnelTransport::class,
            'ngrok' => NgrokTunnelTransport::class,
            'stripe-cli' => StripeCliTunnelTransport::class,
        ]);
        config()->set('preview.hmac.secret', 'configured-hmac-secret');
        config()->set('preview.github.webhook_secret', 'github-preview-secret');
        config()->set('preview.shopify.client_secret', '');
        config()->set('preview.stripe.endpoint_secret', 'whsec_configured_secret');
        config()->set('preview.transport_binaries.cloudflare', 'cloudflared');
        config()->set('preview.transport_binaries.ngrok', '');
        config()->set('preview.transport_binaries.stripe_cli', 'stripe');

        $exitCode = Artisan::call('preview:config', ['--json' => true]);
        $output = Artisan::output();

        self::assertSame(0, $exitCode);
        self::assertSame(json_encode([
            'storage_path' => '/tmp/preview/captures',
            'fixture_path' => '/tmp/preview/fixtures',
            'test_path' => '/tmp/preview/tests',
            'scenario_path' => '/tmp/preview/scenarios',
            'http_capture' => [
                'enabled' => true,
                'path' => '/preview/capture/{provider}',
            ],
            'route_preview' => [
                'enabled' => false,
                'path' => '/preview/route/{route}',
            ],
            'live_enabled' => true,
            'transports' => [
                'cloudflare' => CloudflareTunnelTransport::class,
                'ngrok' => NgrokTunnelTransport::class,
                'stripe-cli' => StripeCliTunnelTransport::class,
            ],
            'providers' => [
                'hmac' => GenericHmacProvider::class,
                'github' => GitHubProvider::class,
                'shopify' => ShopifyProvider::class,
                'stripe' => StripeProvider::class,
            ],
            'redacted_headers' => ['authorization', 'x-secret-token'],
            'provider_secret_status' => [
                'hmac' => [
                    'status' => 'configured',
                    'config_key' => 'preview.hmac.secret',
                ],
                'github' => [
                    'status' => 'placeholder',
                    'config_key' => 'preview.github.webhook_secret',
                ],
                'shopify' => [
                    'status' => 'missing',
                    'config_key' => 'preview.shopify.client_secret',
                ],
                'stripe' => [
                    'status' => 'configured',
                    'config_key' => 'preview.stripe.endpoint_secret',
                ],
            ],
            'transport_binary_status' => [
                'cloudflare' => [
                    'binary' => 'cloudflared',
                    'configured' => true,
                ],
                'ngrok' => [
                    'binary' => '',
                    'configured' => false,
                ],
                'stripe_cli' => [
                    'binary' => 'stripe',
                    'configured' => true,
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL, $output);
        self::assertStringNotContainsString('configured-hmac-secret', $output);
        self::assertStringNotContainsString('whsec_configured_secret', $output);
    }

    public function test_it_outputs_concise_text_without_secret_values(): void
    {
        config()->set('preview.hmac.secret', 'configured-hmac-secret');
        config()->set('preview.github.webhook_secret', 'github-preview-secret');

        $this->artisan('preview:config')
            ->expectsOutput('Preview configuration (redacted; secrets are not printed):')
            ->expectsOutputToContain('Storage path: '.config('preview.storage_path'))
            ->expectsOutputToContain('HTTP capture: enabled')
            ->expectsOutputToContain('Providers:')
            ->expectsOutputToContain('Provider secrets:')
            ->expectsOutputToContain('hmac: configured (preview.hmac.secret)')
            ->expectsOutputToContain('github: placeholder (preview.github.webhook_secret)')
            ->expectsOutputToContain('Transport binaries:')
            ->assertExitCode(0);

        $output = Artisan::output();
        self::assertStringNotContainsString('configured-hmac-secret', $output);
        self::assertStringNotContainsString('github-preview-secret', $output);
    }
}

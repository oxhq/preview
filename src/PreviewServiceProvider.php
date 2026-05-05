<?php

declare(strict_types=1);

namespace Oxhq\Preview;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Oxhq\Preview\Commands\CaptureCommand;
use Oxhq\Preview\Commands\CaptureCompareCommand;
use Oxhq\Preview\Commands\CaptureDoctorCommand;
use Oxhq\Preview\Commands\CaptureExportCommand;
use Oxhq\Preview\Commands\CaptureFixtureCommand;
use Oxhq\Preview\Commands\CaptureIntegrityCommand;
use Oxhq\Preview\Commands\CaptureListCommand;
use Oxhq\Preview\Commands\CapturePruneCommand;
use Oxhq\Preview\Commands\CaptureReplayCommand;
use Oxhq\Preview\Commands\CaptureShowCommand;
use Oxhq\Preview\Commands\CaptureStatsCommand;
use Oxhq\Preview\Commands\CaptureTestCommand;
use Oxhq\Preview\Commands\CaptureTimelineCommand;
use Oxhq\Preview\Commands\CaptureVerifyCommand;
use Oxhq\Preview\Commands\ConfigShowCommand;
use Oxhq\Preview\Commands\FixtureDoctorCommand;
use Oxhq\Preview\Commands\FixtureListCommand;
use Oxhq\Preview\Commands\FixtureStatsCommand;
use Oxhq\Preview\Commands\PreviewDoctorCommand;
use Oxhq\Preview\Commands\ProviderDoctorCommand;
use Oxhq\Preview\Commands\ProviderListCommand;
use Oxhq\Preview\Commands\ProviderSampleCommand;
use Oxhq\Preview\Commands\ProviderSelfTestCommand;
use Oxhq\Preview\Commands\RouteDoctorCommand;
use Oxhq\Preview\Commands\RouteExportCommand;
use Oxhq\Preview\Commands\RouteListCommand;
use Oxhq\Preview\Commands\RoutePreviewCommand;
use Oxhq\Preview\Commands\ScenarioExportCommand;
use Oxhq\Preview\Commands\ScenarioListCommand;
use Oxhq\Preview\Commands\ScenarioMakeCommand;
use Oxhq\Preview\Commands\ScenarioReplayCommand;
use Oxhq\Preview\Commands\ScenarioShowCommand;
use Oxhq\Preview\Commands\ScenarioStatsCommand;
use Oxhq\Preview\Commands\ScenarioTestCommand;
use Oxhq\Preview\Commands\ScenarioValidateCommand;
use Oxhq\Preview\Commands\TransportDoctorCommand;
use Oxhq\Preview\Commands\TransportListCommand;
use Oxhq\Preview\Capture\CaptureRepository;
use Oxhq\Preview\Capture\HttpReplayDispatcher;
use Oxhq\Preview\Capture\ReplayService;
use Oxhq\Preview\Core\CaptureId;
use Oxhq\Preview\Core\GitIgnoreGuard;
use Oxhq\Preview\Core\ProviderRegistry;
use Oxhq\Preview\Core\RedactionPolicy;
use Oxhq\Preview\Core\Transport\CloudflareTunnelTransport;
use Oxhq\Preview\Core\Transport\NgrokTunnelTransport;
use Oxhq\Preview\Core\Transport\StripeCliTunnelTransport;
use Oxhq\Preview\Core\Transport\TransportRegistry;
use Oxhq\Preview\Core\Transport\TunnelTransport;
use Oxhq\Preview\Http\CaptureController;
use Oxhq\Preview\Http\RoutePreviewController;
use Oxhq\Preview\Providers\GenericHmacProvider;
use Oxhq\Preview\Providers\GenericProvider;
use Oxhq\Preview\Providers\GitHubProvider;
use Oxhq\Preview\Providers\PreviewProvider;
use Oxhq\Preview\Providers\ShopifyProvider;
use Oxhq\Preview\Providers\StripeProvider;
use Oxhq\Preview\Route\RoutePreviewService;
use Oxhq\Preview\Scenario\ScenarioRepository;
use Oxhq\Preview\Scenario\ScenarioRunner;
use Oxhq\Preview\Testing\FixtureWriter;
use Oxhq\Preview\Testing\PestTestWriter;
use Oxhq\Preview\Testing\ScenarioPestTestWriter;

class PreviewServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/preview.php', 'preview');

        $this->app->singleton(CaptureId::class);
        $this->app->singleton(GitIgnoreGuard::class);

        $this->app->singleton(RedactionPolicy::class, function (): RedactionPolicy {
            return new RedactionPolicy(config('preview.redact_headers', []));
        });

        $this->app->singleton(ProviderRegistry::class, function (): ProviderRegistry {
            $registry = new ProviderRegistry();

            $registry->register(new GenericProvider());
            $registry->register(new GenericHmacProvider(
                (string) config('preview.hmac.signature_header', 'X-Signature'),
                (string) config('preview.hmac.secret', 'preview-secret'),
                (string) config('preview.hmac.algorithm', 'sha256'),
            ));
            $registry->register(new GitHubProvider(
                (string) config('preview.github.webhook_secret', 'github-preview-secret'),
            ));
            $registry->register(new ShopifyProvider(
                (string) config('preview.shopify.client_secret', 'shopify-preview-secret'),
            ));
            $registry->register(new StripeProvider(
                (string) config('preview.stripe.endpoint_secret', 'whsec_preview'),
                (int) config('preview.stripe.tolerance', 300),
            ));

            foreach ((array) config('preview.providers', []) as $providerClass) {
                if (! is_string($providerClass) || $providerClass === '' || $this->isBuiltInProvider($providerClass)) {
                    continue;
                }

                $provider = $this->app->make($providerClass);

                if ($provider instanceof PreviewProvider) {
                    $registry->register($provider);
                }
            }

            return $registry;
        });

        $this->app->singleton(TransportRegistry::class, function (): TransportRegistry {
            $registry = new TransportRegistry();

            foreach ((array) config('preview.transports', []) as $name => $transport) {
                if (! is_string($name) || ! is_string($transport) || $transport === '') {
                    continue;
                }

                $instance = $this->app->make($transport);

                if ($instance instanceof TunnelTransport) {
                    $registry->register($name, $instance);
                }
            }

            return $registry;
        });

        $this->app->bind(CloudflareTunnelTransport::class, function (): CloudflareTunnelTransport {
            return new CloudflareTunnelTransport(
                binary: (string) config('preview.transport_binaries.cloudflare', 'cloudflared'),
                readinessDelaySeconds: (float) config('preview.transport_readiness_delay.cloudflare', 6),
            );
        });

        $this->app->bind(NgrokTunnelTransport::class, function (): NgrokTunnelTransport {
            return new NgrokTunnelTransport(
                binary: (string) config('preview.transport_binaries.ngrok', 'ngrok'),
            );
        });

        $this->app->bind(StripeCliTunnelTransport::class, function (): StripeCliTunnelTransport {
            return new StripeCliTunnelTransport(
                binary: (string) config('preview.transport_binaries.stripe_cli', 'stripe'),
                capturePathTemplate: (string) config('preview.http_capture.path', '/__preview/capture/{provider}'),
            );
        });

        $this->app->singleton(CaptureRepository::class, function (): CaptureRepository {
            return new CaptureRepository(
                config('preview.storage_path'),
                $this->app->make(RedactionPolicy::class),
                $this->app->make(CaptureId::class),
                $this->app->make(GitIgnoreGuard::class),
            );
        });

        $this->app->singleton(ReplayService::class, function (): ReplayService {
            return new ReplayService(
                $this->app->make(CaptureRepository::class),
                $this->app->make(ProviderRegistry::class),
            );
        });

        $this->app->singleton(HttpReplayDispatcher::class);
        $this->app->singleton(RoutePreviewService::class);
        $this->app->singleton(ScenarioRepository::class, function (): ScenarioRepository {
            return new ScenarioRepository((string) config('preview.scenario_path'));
        });
        $this->app->singleton(ScenarioRunner::class);

        $this->app->singleton(FixtureWriter::class, function (): FixtureWriter {
            return new FixtureWriter(
                config('preview.fixture_path'),
                $this->app->make(RedactionPolicy::class),
                $this->app->make(GitIgnoreGuard::class),
            );
        });

        $this->app->singleton(PestTestWriter::class, function (): PestTestWriter {
            return new PestTestWriter(
                config('preview.test_path'),
                $this->app->make(FixtureWriter::class),
            );
        });

        $this->app->singleton(ScenarioPestTestWriter::class, function (): ScenarioPestTestWriter {
            return new ScenarioPestTestWriter(config('preview.test_path'));
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/preview.php' => config_path('preview.php'),
        ], 'preview-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CaptureCommand::class,
                CaptureCompareCommand::class,
                CaptureDoctorCommand::class,
                CaptureExportCommand::class,
                CaptureFixtureCommand::class,
                CaptureIntegrityCommand::class,
                CaptureListCommand::class,
                CapturePruneCommand::class,
                CaptureReplayCommand::class,
                CaptureShowCommand::class,
                CaptureStatsCommand::class,
                CaptureTestCommand::class,
                CaptureTimelineCommand::class,
                CaptureVerifyCommand::class,
                ConfigShowCommand::class,
                FixtureDoctorCommand::class,
                FixtureListCommand::class,
                FixtureStatsCommand::class,
                PreviewDoctorCommand::class,
                ProviderDoctorCommand::class,
                ProviderListCommand::class,
                ProviderSampleCommand::class,
                ProviderSelfTestCommand::class,
                RouteDoctorCommand::class,
                RouteExportCommand::class,
                RouteListCommand::class,
                RoutePreviewCommand::class,
                ScenarioExportCommand::class,
                ScenarioListCommand::class,
                ScenarioMakeCommand::class,
                ScenarioReplayCommand::class,
                ScenarioShowCommand::class,
                ScenarioStatsCommand::class,
                ScenarioTestCommand::class,
                ScenarioValidateCommand::class,
                TransportDoctorCommand::class,
                TransportListCommand::class,
            ]);
        }

        if ((bool) config('preview.http_capture.enabled', true)) {
            Route::match(
                ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
                (string) config('preview.http_capture.path', '/__preview/capture/{provider}'),
                CaptureController::class,
            )->name('preview.capture');
        }

        if ((bool) config('preview.route_preview.enabled', true)) {
            Route::get(
                (string) config('preview.route_preview.path', '/__preview/route/{route}'),
                RoutePreviewController::class,
            )->name('preview.route.access');
        }
    }

    private function isBuiltInProvider(string $providerClass): bool
    {
        return in_array($providerClass, [
            GenericProvider::class,
            GenericHmacProvider::class,
            GitHubProvider::class,
            ShopifyProvider::class,
            StripeProvider::class,
        ], true);
    }
}

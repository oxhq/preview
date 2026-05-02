<?php

declare(strict_types=1);

namespace Oxhq\Preview;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Oxhq\Preview\Commands\CaptureCommand;
use Oxhq\Preview\Commands\CaptureFixtureCommand;
use Oxhq\Preview\Commands\CaptureListCommand;
use Oxhq\Preview\Commands\CaptureReplayCommand;
use Oxhq\Preview\Commands\CaptureShowCommand;
use Oxhq\Preview\Commands\CaptureTestCommand;
use Oxhq\Preview\Capture\CaptureRepository;
use Oxhq\Preview\Capture\HttpReplayDispatcher;
use Oxhq\Preview\Capture\ReplayService;
use Oxhq\Preview\Core\CaptureId;
use Oxhq\Preview\Core\GitIgnoreGuard;
use Oxhq\Preview\Core\ProviderRegistry;
use Oxhq\Preview\Core\RedactionPolicy;
use Oxhq\Preview\Core\Transport\CloudflareTunnelTransport;
use Oxhq\Preview\Core\Transport\NgrokTunnelTransport;
use Oxhq\Preview\Core\Transport\TransportRegistry;
use Oxhq\Preview\Core\Transport\TunnelTransport;
use Oxhq\Preview\Http\CaptureController;
use Oxhq\Preview\Providers\GenericHmacProvider;
use Oxhq\Preview\Providers\GenericProvider;
use Oxhq\Preview\Providers\StripeProvider;
use Oxhq\Preview\Testing\FixtureWriter;
use Oxhq\Preview\Testing\PestTestWriter;

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
            $registry->register(new StripeProvider(
                (string) config('preview.stripe.endpoint_secret', 'whsec_preview'),
                (int) config('preview.stripe.tolerance', 300),
            ));

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
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/preview.php' => config_path('preview.php'),
        ], 'preview-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CaptureCommand::class,
                CaptureFixtureCommand::class,
                CaptureListCommand::class,
                CaptureReplayCommand::class,
                CaptureShowCommand::class,
                CaptureTestCommand::class,
            ]);
        }

        if ((bool) config('preview.http_capture.enabled', true)) {
            Route::match(
                ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
                (string) config('preview.http_capture.path', '/__preview/capture/{provider}'),
                CaptureController::class,
            )->name('preview.capture');
        }
    }
}

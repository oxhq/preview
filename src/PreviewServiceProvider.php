<?php

declare(strict_types=1);

namespace Oxhq\Preview;

use Illuminate\Support\ServiceProvider;
use Oxhq\Preview\Commands\CaptureCommand;
use Oxhq\Preview\Commands\CaptureFixtureCommand;
use Oxhq\Preview\Commands\CaptureListCommand;
use Oxhq\Preview\Commands\CaptureReplayCommand;
use Oxhq\Preview\Commands\CaptureShowCommand;
use Oxhq\Preview\Commands\CaptureTestCommand;
use Oxhq\Preview\Capture\CaptureRepository;
use Oxhq\Preview\Capture\ReplayService;
use Oxhq\Preview\Core\CaptureId;
use Oxhq\Preview\Core\ProviderRegistry;
use Oxhq\Preview\Core\RedactionPolicy;
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

        $this->app->singleton(CaptureRepository::class, function (): CaptureRepository {
            return new CaptureRepository(
                config('preview.storage_path'),
                $this->app->make(RedactionPolicy::class),
                $this->app->make(CaptureId::class),
            );
        });

        $this->app->singleton(ReplayService::class, function (): ReplayService {
            return new ReplayService(
                $this->app->make(CaptureRepository::class),
                $this->app->make(ProviderRegistry::class),
            );
        });

        $this->app->singleton(FixtureWriter::class, function (): FixtureWriter {
            return new FixtureWriter(
                config('preview.fixture_path'),
                $this->app->make(RedactionPolicy::class),
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
    }
}

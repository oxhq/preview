<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests;

use Oxhq\Preview\Core\CaptureId;
use Oxhq\Preview\Core\ProviderRegistry;
use Oxhq\Preview\Core\RedactionPolicy;
use Oxhq\Preview\Core\Transport\TransportRegistry;
use Oxhq\Preview\Capture\PreviewRequest;
use Oxhq\Preview\Providers\GenericProvider;
use Oxhq\Preview\Providers\VerificationResult;

class FoundationTest extends TestCase
{
    public function test_it_registers_core_services(): void
    {
        $this->assertInstanceOf(CaptureId::class, app(CaptureId::class));
        $this->assertInstanceOf(RedactionPolicy::class, app(RedactionPolicy::class));
        $this->assertInstanceOf(ProviderRegistry::class, app(ProviderRegistry::class));
        $this->assertInstanceOf(TransportRegistry::class, app(TransportRegistry::class));
    }

    public function test_it_registers_http_capture_route(): void
    {
        $this->assertTrue(app('router')->has('preview.capture'));
    }

    public function test_redaction_policy_redacts_sensitive_headers(): void
    {
        $policy = new RedactionPolicy(['authorization', 'cookie']);

        $headers = $policy->redactHeaders([
            'Authorization' => 'Bearer secret',
            'X-Event' => 'kept',
            'Cookie' => 'session=secret',
        ]);

        $this->assertSame('[redacted]', $headers['Authorization']);
        $this->assertSame('kept', $headers['X-Event']);
        $this->assertSame('[redacted]', $headers['Cookie']);
    }

    public function test_it_registers_custom_providers_from_config_without_losing_built_ins(): void
    {
        $this->app['config']->set('preview.providers.custom', ConfiguredPreviewProvider::class);
        $this->app['config']->set('preview.providers.invalid', \stdClass::class);
        $this->app->forgetInstance(ProviderRegistry::class);

        $providers = app(ProviderRegistry::class);

        $this->assertInstanceOf(ConfiguredPreviewProvider::class, $providers->get('custom'));
        $this->assertSame('generic', $providers->get('generic')->name());
        $this->assertSame('hmac', $providers->get('hmac')->name());
        $this->assertSame('stripe', $providers->get('stripe')->name());
    }
}

final class ConfiguredPreviewProvider extends GenericProvider
{
    public function name(): string
    {
        return 'custom';
    }

    public function verify(PreviewRequest $request): VerificationResult
    {
        return VerificationResult::verified();
    }
}

<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use Illuminate\Support\Facades\Artisan;
use Oxhq\Preview\Core\ProviderRegistry;
use Oxhq\Preview\Providers\GenericProvider;
use Oxhq\Preview\Providers\ProviderCapability;
use Oxhq\Preview\Tests\TestCase;

final class ProviderDoctorCommandTest extends TestCase
{
    public function test_it_reports_provider_diagnostics_without_printing_secret_values(): void
    {
        config()->set('preview.hmac.secret', 'configured-hmac-secret');
        config()->set('preview.github.webhook_secret', 'configured-github-secret');
        config()->set('preview.shopify.client_secret', 'configured-shopify-secret');
        config()->set('preview.stripe.endpoint_secret', 'whsec_configured_secret');
        $this->app->forgetInstance(ProviderRegistry::class);

        $exitCode = Artisan::call('preview:provider:doctor', [
            '--json' => true,
        ]);

        $rows = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $providers = $this->rowsByName($rows);

        self::assertSame(0, $exitCode);
        self::assertSame([
            'generic',
            'hmac',
            'github',
            'shopify',
            'stripe',
        ], array_keys($providers));

        self::assertSame('ready', $providers['generic']['configuration_status']);
        self::assertSame(false, $providers['generic']['can_sign']);
        self::assertSame(true, $providers['generic']['ready']);

        self::assertSame('ready', $providers['hmac']['configuration_status']);
        self::assertSame(true, $providers['hmac']['can_sign']);
        self::assertSame(true, $providers['hmac']['ready']);
        self::assertSame('preview.hmac.secret', $providers['hmac']['config_key']);
        self::assertSame([
            'VerifiesSignature',
            'ExtractsEventType',
            'ReSignsPayload',
            'GeneratesFixture',
            'GeneratesTest',
        ], $providers['hmac']['capabilities']);

        self::assertSame('ready', $providers['github']['configuration_status']);
        self::assertSame('ready', $providers['shopify']['configuration_status']);
        self::assertSame('ready', $providers['stripe']['configuration_status']);
        self::assertStringNotContainsString('configured-hmac-secret', Artisan::output());
        self::assertStringNotContainsString('configured-github-secret', Artisan::output());
        self::assertStringNotContainsString('configured-shopify-secret', Artisan::output());
        self::assertStringNotContainsString('whsec_configured_secret', Artisan::output());
    }

    public function test_it_warns_when_built_in_providers_use_package_placeholder_secrets(): void
    {
        config()->set('preview.hmac.secret', 'preview-secret');
        config()->set('preview.github.webhook_secret', 'github-preview-secret');
        config()->set('preview.shopify.client_secret', 'shopify-preview-secret');
        config()->set('preview.stripe.endpoint_secret', 'whsec_preview');
        $this->app->forgetInstance(ProviderRegistry::class);

        $exitCode = Artisan::call('preview:provider:doctor', [
            '--json' => true,
        ]);

        $rows = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $providers = $this->rowsByName($rows);

        self::assertSame(0, $exitCode);

        foreach (['hmac', 'github', 'shopify', 'stripe'] as $providerName) {
            self::assertSame('warning', $providers[$providerName]['configuration_status']);
            self::assertSame(false, $providers[$providerName]['ready']);
            self::assertStringContainsString('placeholder/default secret', $providers[$providerName]['configuration_message']);
        }

        self::assertStringNotContainsString('preview-secret', Artisan::output());
        self::assertStringNotContainsString('github-preview-secret', Artisan::output());
        self::assertStringNotContainsString('shopify-preview-secret', Artisan::output());
        self::assertStringNotContainsString('whsec_preview', Artisan::output());
    }

    public function test_it_reports_human_readable_provider_diagnostics_for_custom_providers(): void
    {
        $registry = new ProviderRegistry();
        $registry->register(new GenericProvider());
        $registry->register(new DoctorCommandCustomProvider());
        $this->app->instance(ProviderRegistry::class, $registry);

        $exitCode = Artisan::call('preview:provider:doctor');
        $output = Artisan::output();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Preview provider diagnostics:', $output);
        self::assertStringContainsString(' - generic [built-in]: '.GenericProvider::class, $output);
        self::assertStringContainsString('   Capabilities: ExtractsEventType, GeneratesFixture, GeneratesTest', $output);
        self::assertStringContainsString('   Can sign: no', $output);
        self::assertStringContainsString('   Configuration: ready (no secret required)', $output);
        self::assertStringContainsString('   Ready: yes', $output);
        self::assertStringContainsString(' - acme [custom]: '.DoctorCommandCustomProvider::class, $output);
        self::assertStringContainsString('   Capabilities: VerifiesSignature, GeneratesFixture', $output);
        self::assertStringContainsString('   Can sign: yes', $output);
        self::assertStringContainsString('   Configuration: unknown (custom provider configuration is not inspectable)', $output);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function rowsByName(array $rows): array
    {
        $indexed = [];

        foreach ($rows as $row) {
            $indexed[(string) $row['name']] = $row;
        }

        return $indexed;
    }
}

final class DoctorCommandCustomProvider extends GenericProvider
{
    public function name(): string
    {
        return 'acme';
    }

    public function capabilities(): array
    {
        return [
            ProviderCapability::VerifiesSignature,
            ProviderCapability::GeneratesFixture,
        ];
    }

    public function canSign(): bool
    {
        return true;
    }
}

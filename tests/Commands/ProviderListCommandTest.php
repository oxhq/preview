<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use Oxhq\Preview\Core\ProviderRegistry;
use Oxhq\Preview\Providers\GenericProvider;
use Oxhq\Preview\Providers\ProviderCapability;
use Oxhq\Preview\Tests\TestCase;

final class ProviderListCommandTest extends TestCase
{
    public function test_it_lists_registered_provider_names_sources_and_capabilities(): void
    {
        $registry = new ProviderRegistry();
        $registry->register(new GenericProvider());
        $registry->register(new ListCommandCustomProvider());
        $this->app->instance(ProviderRegistry::class, $registry);

        $this->artisan('preview:provider:list')
            ->expectsOutput('Preview providers:')
            ->expectsOutput(' - generic [built-in] '.GenericProvider::class.': ExtractsEventType, GeneratesFixture, GeneratesTest')
            ->expectsOutput(' - acme [custom] '.ListCommandCustomProvider::class.': VerifiesSignature, GeneratesFixture')
            ->assertExitCode(0);
    }

    public function test_it_outputs_machine_readable_json(): void
    {
        $registry = new ProviderRegistry();
        $registry->register(new GenericProvider());
        $registry->register(new ListCommandCustomProvider());
        $this->app->instance(ProviderRegistry::class, $registry);

        $this->artisan('preview:provider:list', ['--json' => true])
            ->expectsOutput(json_encode([
                [
                    'name' => 'generic',
                    'source' => 'built-in',
                    'class' => GenericProvider::class,
                    'capabilities' => [
                        'ExtractsEventType',
                        'GeneratesFixture',
                        'GeneratesTest',
                    ],
                ],
                [
                    'name' => 'acme',
                    'source' => 'custom',
                    'class' => ListCommandCustomProvider::class,
                    'capabilities' => [
                        'VerifiesSignature',
                        'GeneratesFixture',
                    ],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
            ->assertExitCode(0);
    }

    public function test_default_registry_exposes_laravel_preview_built_in_providers(): void
    {
        $this->artisan('preview:provider:list')
            ->expectsOutputToContain('generic')
            ->expectsOutput(' - hmac [built-in] '.\Oxhq\Preview\Providers\GenericHmacProvider::class.': VerifiesSignature, ExtractsEventType, ReSignsPayload, GeneratesFixture, GeneratesTest')
            ->expectsOutputToContain('github')
            ->expectsOutputToContain('shopify')
            ->expectsOutputToContain('stripe')
            ->assertExitCode(0);
    }
}

final class ListCommandCustomProvider extends GenericProvider
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
}

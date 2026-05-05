<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use Oxhq\Preview\Commands\ProviderSelfTestCommand;
use Oxhq\Preview\Core\ProviderRegistry;
use Oxhq\Preview\Providers\GenericProvider;
use Oxhq\Preview\Tests\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ProviderSelfTestCommandTest extends TestCase
{
    public function test_it_self_tests_every_built_in_provider_as_json_without_printing_secrets(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute(['--json' => true]);

        $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $providers = $this->rowsByProvider($rows);

        self::assertSame(0, $exitCode);
        self::assertSame([
            'generic',
            'hmac',
            'github',
            'shopify',
            'stripe',
        ], array_keys($providers));

        self::assertSame('preview.sample', $providers['generic']['event']);
        self::assertSame(false, $providers['generic']['verified']);
        self::assertSame('skipped', $providers['generic']['status']);
        self::assertTrue($providers['generic']['ok']);
        self::assertSame('Generic provider does not verify request signatures.', $providers['generic']['message']);

        foreach (['hmac', 'github', 'shopify', 'stripe'] as $providerName) {
            self::assertSame(true, $providers[$providerName]['verified'], $providerName);
            self::assertSame('verified', $providers[$providerName]['status'], $providerName);
            self::assertTrue($providers[$providerName]['ok'], $providerName);
            self::assertNull($providers[$providerName]['message'], $providerName);
        }

        self::assertSame('push', $providers['github']['event']);
        self::assertSame('orders/create', $providers['shopify']['event']);
        self::assertSame('checkout.session.completed', $providers['stripe']['event']);

        self::assertStringNotContainsString('test-secret', $tester->getDisplay());
        self::assertStringNotContainsString('github-test-secret', $tester->getDisplay());
        self::assertStringNotContainsString('shopify-test-secret', $tester->getDisplay());
        self::assertStringNotContainsString('whsec_test', $tester->getDisplay());
        self::assertStringNotContainsString('X-Signature', $tester->getDisplay());
        self::assertStringNotContainsString('Stripe-Signature', $tester->getDisplay());
    }

    public function test_it_self_tests_a_single_built_in_provider_with_human_readable_output(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute(['provider' => 'github']);

        $output = $tester->getDisplay();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Preview provider self-test:', $output);
        self::assertStringContainsString(' - github: verified', $output);
        self::assertStringContainsString('Event: push', $output);
        self::assertStringNotContainsString('github-test-secret', $output);
        self::assertStringNotContainsString('X-Hub-Signature-256', $output);
    }

    public function test_it_rejects_custom_providers_even_when_registered(): void
    {
        $registry = $this->app->make(ProviderRegistry::class);
        $registry->register(new ProviderSelfTestCustomProvider());

        $tester = $this->commandTester();

        $exitCode = $tester->execute(['provider' => 'acme']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Provider self-tests are only available for built-in providers: generic, hmac, github, shopify, stripe.', $tester->getDisplay());
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function rowsByProvider(array $rows): array
    {
        $indexed = [];

        foreach ($rows as $row) {
            $indexed[(string) $row['provider']] = $row;
        }

        return $indexed;
    }

    private function commandTester(): CommandTester
    {
        $command = new ProviderSelfTestCommand($this->app->make(ProviderRegistry::class));
        $command->setLaravel($this->app);

        return new CommandTester($command);
    }
}

final class ProviderSelfTestCustomProvider extends GenericProvider
{
    public function name(): string
    {
        return 'acme';
    }
}

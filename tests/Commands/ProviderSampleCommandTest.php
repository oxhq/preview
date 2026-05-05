<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use Oxhq\Preview\Commands\ProviderSampleCommand;
use Oxhq\Preview\Core\ProviderRegistry;
use Oxhq\Preview\Providers\GenericProvider;
use Oxhq\Preview\Tests\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ProviderSampleCommandTest extends TestCase
{
    public function test_it_outputs_json_sample_for_a_signing_provider_without_printing_secrets(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute([
            'provider' => 'github',
            '--event' => 'pull_request',
            '--json' => true,
        ]);

        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(0, $exitCode);
        self::assertSame('github', $payload['provider']);
        self::assertSame('pull_request', $payload['event']);
        self::assertSame('POST', $payload['method']);
        self::assertSame('/__preview/capture/github', $payload['path']);
        self::assertSame('application/json', $payload['headers']['Content-Type']);
        self::assertSame('pull_request', $payload['headers']['X-GitHub-Event']);
        self::assertArrayHasKey('X-Hub-Signature-256', $payload['headers']);
        self::assertStringStartsWith('sha256=', $payload['headers']['X-Hub-Signature-256']);
        self::assertSame('github-pull_request', $payload['fixture_name']);
        self::assertSame(true, $payload['signed']);
        self::assertStringContainsString('"action":"opened"', $payload['raw_body']);
        self::assertStringNotContainsString('github-test-secret', $tester->getDisplay());
    }

    public function test_it_outputs_command_friendly_text_for_a_generic_sample(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute([
            'provider' => 'generic',
            '--event' => 'order.created',
        ]);

        $output = $tester->getDisplay();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Preview provider sample: generic', $output);
        self::assertStringContainsString('Event: order.created', $output);
        self::assertStringContainsString('Signed: no', $output);
        self::assertStringContainsString('X-Preview-Event: order.created', $output);
        self::assertStringContainsString('Body:', $output);
        self::assertStringContainsString('"event":"order.created"', $output);
    }

    public function test_it_builds_samples_for_every_built_in_provider(): void
    {
        $samples = [];

        foreach (['generic', 'hmac', 'github', 'shopify', 'stripe'] as $provider) {
            $tester = $this->commandTester();
            $exitCode = $tester->execute([
                'provider' => $provider,
                '--json' => true,
            ]);

            self::assertSame(0, $exitCode, $provider);

            $samples[$provider] = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        }

        self::assertSame('preview.sample', $samples['generic']['headers']['X-Preview-Event']);
        self::assertSame(false, $samples['generic']['signed']);
        self::assertArrayNotHasKey('X-Signature', $samples['generic']['headers']);

        self::assertSame(hash_hmac('sha256', $samples['hmac']['raw_body'], 'test-secret'), $samples['hmac']['headers']['X-Signature']);
        self::assertSame(true, $samples['hmac']['signed']);

        self::assertSame('push', $samples['github']['headers']['X-GitHub-Event']);
        self::assertStringStartsWith('sha256=', $samples['github']['headers']['X-Hub-Signature-256']);

        self::assertSame('orders/create', $samples['shopify']['headers']['X-Shopify-Topic']);
        self::assertArrayHasKey('X-Shopify-Hmac-Sha256', $samples['shopify']['headers']);

        self::assertSame('checkout.session.completed', $samples['stripe']['event']);
        self::assertStringStartsWith('t=', $samples['stripe']['headers']['Stripe-Signature']);
        self::assertStringContainsString(',v1=', $samples['stripe']['headers']['Stripe-Signature']);
    }

    public function test_it_rejects_custom_providers_even_when_registered(): void
    {
        $registry = $this->app->make(ProviderRegistry::class);
        $registry->register(new ProviderSampleCustomProvider());

        $tester = $this->commandTester();

        $exitCode = $tester->execute(['provider' => 'acme']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Provider samples are only available for built-in providers: generic, hmac, github, shopify, stripe.', $tester->getDisplay());
    }

    private function commandTester(): CommandTester
    {
        $command = new ProviderSampleCommand($this->app->make(ProviderRegistry::class));
        $command->setLaravel($this->app);

        return new CommandTester($command);
    }
}

final class ProviderSampleCustomProvider extends GenericProvider
{
    public function name(): string
    {
        return 'acme';
    }
}

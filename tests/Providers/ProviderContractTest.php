<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Providers;

use Oxhq\Preview\Capture\CaptureRepository;
use Oxhq\Preview\Capture\PreviewRequest;
use Oxhq\Preview\Capture\ReplayService;
use Oxhq\Preview\Core\ProviderRegistry;
use Oxhq\Preview\Providers\GenericHmacProvider;
use Oxhq\Preview\Providers\GenericProvider;
use Oxhq\Preview\Providers\GitHubProvider;
use Oxhq\Preview\Providers\PreviewProvider;
use Oxhq\Preview\Providers\ProviderCapability;
use Oxhq\Preview\Providers\ShopifyProvider;
use Oxhq\Preview\Providers\StripeProvider;
use Oxhq\Preview\Testing\FixtureWriter;
use Oxhq\Preview\Testing\PestTestWriter;
use Oxhq\Preview\Testing\PreviewFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProviderContractTest extends TestCase
{
    /**
     * @param list<ProviderCapability> $requiredCapabilities
     */
    #[DataProvider('providerCases')]
    public function test_provider_capture_replay_fixture_and_test_contract(
        PreviewProvider $provider,
        PreviewRequest $request,
        ?string $expectedEvent,
        string $expectedFixtureName,
        string $expectedSigningMode,
        array $requiredCapabilities,
    ): void {
        $root = sys_get_temp_dir().'/preview-provider-contract-'.bin2hex(random_bytes(4));
        $repository = new CaptureRepository($root.'/captures');
        $registry = new ProviderRegistry();
        $registry->register($provider);

        $record = $repository->store($request, $provider);
        $replay = new ReplayService($repository, $registry);
        $fixtureWriter = new FixtureWriter($root.'/fixtures');
        $testWriter = new PestTestWriter($root.'/Feature', $fixtureWriter);

        $this->assertSame($provider->name(), $record->provider);
        $this->assertSame($expectedEvent, $record->eventType);
        $this->assertSame($expectedFixtureName, $record->metadata['fixture_name']);
        $this->assertSame($request->rawBody, $replay->exact($record)['raw_body']);
        $this->assertSame($request->headers, $replay->exact($record)['headers']);

        foreach ($requiredCapabilities as $capability) {
            $this->assertContains($capability, $provider->capabilities());
        }

        if ($provider->canSign()) {
            $resigned = $replay->resign($record);

            $this->assertSame('resign', $resigned['mode']);
            $this->assertTrue($provider->verify(PreviewRequest::make(
                provider: $provider->name(),
                method: $record->method,
                path: $record->path,
                headers: $resigned['headers'],
                rawBody: $record->rawBody(),
            ))->verified);
        }

        $fixture = $fixtureWriter->write($record, $provider->canSign());
        $testPath = $testWriter->write($record, $provider->canSign());

        $this->assertInstanceOf(PreviewFixture::class, $fixture);
        $this->assertSame($provider->name(), $fixture->providerName());
        $this->assertSame($expectedSigningMode, $fixture->signingMode());
        $this->assertSame($expectedEvent, $fixture->eventType());
        $this->assertSame($request->path, $fixture->endpointPath());
        $this->assertSame($request->method, $fixture->requestMethod());
        $this->assertSame($request->rawBody, $fixture->rawBody());
        $this->assertFileExists($testPath);
        $this->assertPhpFileIsLintable($fixtureWriter->fixturePath($record));
        $this->assertPhpFileIsLintable($testPath);
        $this->assertStringContainsString(
            $provider->canSign() ? '$fixture->freshSignedHeaders()' : '$fixture->headers()',
            (string) file_get_contents($testPath),
        );
    }

    /**
     * @return iterable<string, array{0: PreviewProvider, 1: PreviewRequest, 2: string|null, 3: string, 4: string, 5: list<ProviderCapability>}>
     */
    public static function providerCases(): iterable
    {
        $genericBody = '{"event":"generic.created"}';

        yield 'generic' => [
            new GenericProvider(),
            PreviewRequest::make(
                provider: 'generic',
                method: 'POST',
                path: '/webhook/generic',
                headers: ['X-Preview-Event' => 'generic.created'],
                rawBody: $genericBody,
            ),
            'generic.created',
            'generic.created',
            'exact',
            [
                ProviderCapability::ExtractsEventType,
                ProviderCapability::GeneratesFixture,
                ProviderCapability::GeneratesTest,
            ],
        ];

        $hmacBody = '{"event":"hmac.created"}';
        $hmacProvider = new GenericHmacProvider('X-Custom-Signature', 'preview-secret');

        yield 'hmac' => [
            $hmacProvider,
            PreviewRequest::make(
                provider: 'hmac',
                method: 'POST',
                path: '/webhook/hmac',
                headers: [
                    'X-Preview-Event' => 'hmac.created',
                    'X-Custom-Signature' => hash_hmac('sha256', $hmacBody, 'preview-secret'),
                ],
                rawBody: $hmacBody,
            ),
            'hmac.created',
            'hmac.created',
            'resign',
            [
                ProviderCapability::VerifiesSignature,
                ProviderCapability::ExtractsEventType,
                ProviderCapability::ReSignsPayload,
                ProviderCapability::GeneratesFixture,
                ProviderCapability::GeneratesTest,
            ],
        ];

        $githubBody = '{"action":"opened","repository":{"full_name":"oxhq/preview"}}';
        $githubProvider = new GitHubProvider('github-test-secret');

        yield 'github' => [
            $githubProvider,
            PreviewRequest::make(
                provider: 'github',
                method: 'POST',
                path: '/webhook/github',
                headers: $githubProvider->sign($githubBody, [
                    'X-GitHub-Event' => 'pull_request',
                ]),
                rawBody: $githubBody,
            ),
            'pull_request',
            'github-pull_request',
            'resign',
            [
                ProviderCapability::VerifiesSignature,
                ProviderCapability::ExtractsEventType,
                ProviderCapability::ReSignsPayload,
                ProviderCapability::GeneratesFixture,
                ProviderCapability::GeneratesTest,
            ],
        ];

        $shopifyBody = '{"id":123,"email":"buyer@example.test"}';
        $shopifyProvider = new ShopifyProvider('shopify-test-secret');

        yield 'shopify' => [
            $shopifyProvider,
            PreviewRequest::make(
                provider: 'shopify',
                method: 'POST',
                path: '/webhook/shopify',
                headers: $shopifyProvider->sign($shopifyBody, [
                    'X-Shopify-Topic' => 'orders/create',
                ]),
                rawBody: $shopifyBody,
            ),
            'orders/create',
            'shopify-orders-create',
            'resign',
            [
                ProviderCapability::VerifiesSignature,
                ProviderCapability::ExtractsEventType,
                ProviderCapability::ReSignsPayload,
                ProviderCapability::GeneratesFixture,
                ProviderCapability::GeneratesTest,
            ],
        ];

        $stripeBody = '{"id":"evt_123","type":"checkout.session.completed"}';
        $stripeProvider = new StripeProvider('whsec_test');

        yield 'stripe' => [
            $stripeProvider,
            PreviewRequest::make(
                provider: 'stripe',
                method: 'POST',
                path: '/webhook/stripe',
                headers: $stripeProvider->sign($stripeBody),
                rawBody: $stripeBody,
            ),
            'checkout.session.completed',
            'stripe-checkout-session-completed',
            'resign',
            [
                ProviderCapability::VerifiesSignature,
                ProviderCapability::ExtractsEventType,
                ProviderCapability::ReSignsPayload,
                ProviderCapability::GeneratesFixture,
                ProviderCapability::GeneratesTest,
            ],
        ];
    }

    private function assertPhpFileIsLintable(string $path): void
    {
        $output = [];
        $exitCode = 1;

        exec(PHP_BINARY.' -l '.escapeshellarg($path), $output, $exitCode);

        $this->assertSame(0, $exitCode, implode(PHP_EOL, $output));
    }
}

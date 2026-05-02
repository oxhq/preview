<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Acceptance;

use Oxhq\Preview\Capture\CaptureRepository;
use Oxhq\Preview\Capture\HttpReplayDispatcher;
use Oxhq\Preview\Capture\PreviewRequest;
use Oxhq\Preview\Capture\ReplayResult;
use Oxhq\Preview\Capture\ReplayService;
use Oxhq\Preview\Core\ProviderRegistry;
use Oxhq\Preview\Providers\GenericHmacProvider;
use Oxhq\Preview\Providers\StripeProvider;
use Oxhq\Preview\Tests\TestCase;

final class ReplayDispatchAcceptanceTest extends TestCase
{
    public function test_exact_replay_dispatch_preserves_raw_headers_and_body(): void
    {
        $captured = [];
        $repository = app(CaptureRepository::class);
        $provider = new GenericHmacProvider('X-Custom-Signature', 'test-secret');
        $body = '{"event":"hmac.created"}';
        $signature = hash_hmac('sha256', $body, 'test-secret');

        $record = $repository->store(
            PreviewRequest::make(
                'hmac',
                'POST',
                '/webhook/hmac',
                ['attempt' => '1'],
                [
                    'Authorization' => 'Bearer local-secret',
                    'Cookie' => 'session=local-secret',
                    'X-Custom-Signature' => $signature,
                ],
                $body,
            ),
            $provider,
        );

        $dispatcher = new HttpReplayDispatcher(
            function (string $url, string $method, array $headers, string $body, array $payload) use (&$captured): ReplayResult {
                $captured = compact('url', 'method', 'headers', 'body', 'payload');

                return new ReplayResult(204, '');
            },
        );

        $payload = app(ReplayService::class)->exact($record);
        $result = $dispatcher->dispatch($payload, 'https://receiver.test');

        $this->assertTrue($result->successful());
        $this->assertSame('https://receiver.test/webhook/hmac?attempt=1', $captured['url']);
        $this->assertSame('POST', $captured['method']);
        $this->assertSame($body, $captured['body']);
        $this->assertContains('Authorization: Bearer local-secret', $captured['headers']);
        $this->assertContains('Cookie: session=local-secret', $captured['headers']);
        $this->assertContains('X-Custom-Signature: '.$signature, $captured['headers']);

        $loaded = $repository->find($record->id);
        $this->assertSame('[redacted]', $loaded->headers['Authorization']);
        $this->assertSame('[redacted]', $loaded->headers['Cookie']);
    }

    public function test_resign_replay_refreshes_hmac_and_stripe_signatures(): void
    {
        $registry = app(ProviderRegistry::class);
        $repository = app(CaptureRepository::class);

        $hmacProvider = new GenericHmacProvider('X-Custom-Signature', 'test-secret');
        $hmacBody = '{"event":"hmac.created"}';
        $hmacRecord = $repository->store(
            PreviewRequest::make(
                'hmac',
                'POST',
                '/webhook/hmac',
                [],
                ['X-Custom-Signature' => 'old-signature'],
                $hmacBody,
            ),
            $hmacProvider,
        );

        $hmacPayload = app(ReplayService::class)->resign($hmacRecord);

        $this->assertSame(hash_hmac('sha256', $hmacBody, 'test-secret'), $hmacPayload['headers']['X-Custom-Signature']);
        $this->assertTrue($hmacProvider->verify(PreviewRequest::make(
            'hmac',
            'POST',
            '/webhook/hmac',
            [],
            ['X-Custom-Signature' => $hmacPayload['headers']['X-Custom-Signature']],
            $hmacBody,
        ))->verified);

        $stripeProvider = $registry->get('stripe');
        $this->assertInstanceOf(StripeProvider::class, $stripeProvider);

        $stripeBody = '{"id":"evt_123","type":"checkout.session.completed"}';
        $stripeRecord = $repository->store(
            PreviewRequest::make(
                'stripe',
                'POST',
                '/webhook/stripe',
                [],
                ['Stripe-Signature' => 't=1,v1=old'],
                $stripeBody,
            ),
            $stripeProvider,
        );

        $stripePayload = app(ReplayService::class)->resign($stripeRecord);

        $this->assertNotSame('t=1,v1=old', $stripePayload['headers']['Stripe-Signature']);
        $this->assertTrue($stripeProvider->verify(PreviewRequest::make(
            'stripe',
            'POST',
            '/webhook/stripe',
            [],
            ['Stripe-Signature' => $stripePayload['headers']['Stripe-Signature']],
            $stripeBody,
        ))->verified);
    }
}

<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Providers;

use Oxhq\Preview\Capture\PreviewRequest;
use Oxhq\Preview\Providers\ProviderCapability;
use Oxhq\Preview\Providers\StripeProvider;
use PHPUnit\Framework\TestCase;

final class StripeProviderTest extends TestCase
{
    public function test_it_verifies_stripe_signature_and_extracts_event_type(): void
    {
        $provider = new StripeProvider('whsec_test');
        $body = '{"id":"evt_123","type":"checkout.session.completed"}';
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, 'whsec_test');

        $request = PreviewRequest::make(
            provider: 'stripe',
            method: 'POST',
            path: '/webhook/stripe',
            headers: ['Stripe-Signature' => sprintf('t=%d,v1=%s', $timestamp, $signature)],
            rawBody: $body,
        );

        $this->assertTrue($provider->verify($request)->verified);
        $this->assertSame('checkout.session.completed', $provider->eventType($request));
        $this->assertSame('stripe-checkout-session-completed', $provider->fixtureName($request));
    }

    public function test_it_rejects_invalid_stripe_signature(): void
    {
        $provider = new StripeProvider('whsec_test');
        $body = '{"id":"evt_123","type":"checkout.session.completed"}';

        $result = $provider->verify(PreviewRequest::make(
            provider: 'stripe',
            method: 'POST',
            path: '/webhook/stripe',
            headers: ['Stripe-Signature' => sprintf('t=%d,v1=bad', time())],
            rawBody: $body,
        ));

        $this->assertFalse($result->verified);
        $this->assertSame('Invalid Stripe signature.', $result->message);
    }

    public function test_it_rejects_stale_stripe_signature(): void
    {
        $provider = new StripeProvider('whsec_test', toleranceSeconds: 300);
        $body = '{"id":"evt_123","type":"checkout.session.completed"}';
        $timestamp = time() - 301;
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, 'whsec_test');

        $result = $provider->verify(PreviewRequest::make(
            provider: 'stripe',
            method: 'POST',
            path: '/webhook/stripe',
            headers: ['Stripe-Signature' => sprintf('t=%d,v1=%s', $timestamp, $signature)],
            rawBody: $body,
        ));

        $this->assertFalse($result->verified);
        $this->assertSame('Stripe signature timestamp is outside the allowed tolerance.', $result->message);
    }

    public function test_it_signs_payload_with_fresh_stripe_signature(): void
    {
        $provider = new StripeProvider('whsec_test');
        $body = '{"id":"evt_123","type":"checkout.session.completed"}';

        $headers = $provider->sign($body, ['Content-Type' => 'application/json']);

        $this->assertTrue($provider->canSign());
        $this->assertSame('application/json', $headers['Content-Type']);
        $this->assertArrayHasKey('Stripe-Signature', $headers);
        $this->assertTrue($provider->verify(PreviewRequest::make(
            provider: 'stripe',
            method: 'POST',
            path: '/webhook/stripe',
            headers: ['Stripe-Signature' => $headers['Stripe-Signature']],
            rawBody: $body,
        ))->verified);
        $this->assertContains(ProviderCapability::VerifiesSignature, $provider->capabilities());
        $this->assertContains(ProviderCapability::ReSignsPayload, $provider->capabilities());
    }
}

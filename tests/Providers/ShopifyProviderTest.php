<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Providers;

use Oxhq\Preview\Capture\PreviewRequest;
use Oxhq\Preview\Providers\ProviderCapability;
use Oxhq\Preview\Providers\ShopifyProvider;
use PHPUnit\Framework\TestCase;

final class ShopifyProviderTest extends TestCase
{
    public function test_it_verifies_shopify_signature_using_raw_body(): void
    {
        $provider = new ShopifyProvider('shpss_test');
        $body = '{"id":123,"note":"spacing matters"}';
        $signature = base64_encode(hash_hmac('sha256', $body, 'shpss_test', true));

        $request = PreviewRequest::make(
            provider: 'shopify',
            method: 'POST',
            path: '/webhook/shopify',
            headers: ['X-Shopify-Hmac-Sha256' => $signature],
            rawBody: $body,
        );

        $this->assertTrue($provider->verify($request)->verified);
    }

    public function test_it_rejects_missing_shopify_signature(): void
    {
        $provider = new ShopifyProvider('shpss_test');

        $result = $provider->verify(PreviewRequest::make(
            provider: 'shopify',
            method: 'POST',
            path: '/webhook/shopify',
            rawBody: '{"id":123}',
        ));

        $this->assertFalse($result->verified);
        $this->assertSame('Missing X-Shopify-Hmac-Sha256 header.', $result->message);
    }

    public function test_it_rejects_invalid_shopify_signature(): void
    {
        $provider = new ShopifyProvider('shpss_test');

        $result = $provider->verify(PreviewRequest::make(
            provider: 'shopify',
            method: 'POST',
            path: '/webhook/shopify',
            headers: ['X-Shopify-Hmac-Sha256' => base64_encode('bad')],
            rawBody: '{"id":123}',
        ));

        $this->assertFalse($result->verified);
        $this->assertSame('Invalid Shopify signature.', $result->message);
    }

    public function test_it_extracts_topic_and_generates_safe_fixture_name(): void
    {
        $provider = new ShopifyProvider('shpss_test');
        $request = PreviewRequest::make(
            provider: 'shopify',
            method: 'POST',
            path: '/webhook/shopify',
            headers: ['X-Shopify-Topic' => 'orders/create.v2'],
            rawBody: '{"id":123}',
        );

        $this->assertSame('orders/create.v2', $provider->eventType($request));
        $this->assertSame('shopify-orders-create.v2', $provider->fixtureName($request));
    }

    public function test_it_uses_shopify_event_fixture_name_without_topic(): void
    {
        $provider = new ShopifyProvider('shpss_test');

        $this->assertSame('shopify-event', $provider->fixtureName(PreviewRequest::make(
            provider: 'shopify',
            method: 'POST',
            path: '/webhook/shopify',
        )));
    }

    public function test_it_signs_payload_with_shopify_signature(): void
    {
        $provider = new ShopifyProvider('shpss_test');
        $body = '{"id":123}';

        $headers = $provider->sign($body, ['Content-Type' => 'application/json']);

        $this->assertSame('application/json', $headers['Content-Type']);
        $this->assertArrayHasKey('X-Shopify-Hmac-Sha256', $headers);
        $this->assertTrue($provider->verify(PreviewRequest::make(
            provider: 'shopify',
            method: 'POST',
            path: '/webhook/shopify',
            headers: ['X-Shopify-Hmac-Sha256' => $headers['X-Shopify-Hmac-Sha256']],
            rawBody: $body,
        ))->verified);
    }

    public function test_it_exposes_shopify_provider_capabilities(): void
    {
        $provider = new ShopifyProvider('shpss_test');

        $this->assertSame('shopify', $provider->name());
        $this->assertTrue($provider->canSign());
        $this->assertSame([
            ProviderCapability::VerifiesSignature,
            ProviderCapability::ExtractsEventType,
            ProviderCapability::ReSignsPayload,
            ProviderCapability::GeneratesFixture,
            ProviderCapability::GeneratesTest,
        ], $provider->capabilities());
    }
}

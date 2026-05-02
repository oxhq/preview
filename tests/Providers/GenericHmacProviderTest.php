<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Providers;

use Oxhq\Preview\Providers\GenericHmacProvider;
use Oxhq\Preview\Providers\ProviderCapability;
use Oxhq\Preview\Capture\PreviewRequest;
use PHPUnit\Framework\TestCase;

final class GenericHmacProviderTest extends TestCase
{
    public function test_it_verifies_hmac_signature(): void
    {
        $provider = new GenericHmacProvider('X-Signature', 'secret');
        $body = '{"event":"order.created"}';
        $signature = hash_hmac('sha256', $body, 'secret');

        $result = $provider->verify(PreviewRequest::make(
            provider: 'generic-hmac',
            method: 'POST',
            path: '/webhook',
            headers: ['X-Signature' => $signature],
            rawBody: $body,
        ));

        $this->assertTrue($result->verified);
    }

    public function test_it_accepts_sha256_prefixed_hmac_signature(): void
    {
        $provider = new GenericHmacProvider('X-Signature', 'secret');
        $body = '{"event":"order.created"}';
        $signature = 'sha256=' . hash_hmac('sha256', $body, 'secret');

        $result = $provider->verify(PreviewRequest::make(
            provider: 'generic-hmac',
            method: 'POST',
            path: '/webhook',
            headers: ['X-Signature' => $signature],
            rawBody: $body,
        ));

        $this->assertTrue($result->verified);
    }

    public function test_it_rejects_invalid_hmac_signature(): void
    {
        $provider = new GenericHmacProvider('X-Signature', 'secret');

        $result = $provider->verify(PreviewRequest::make(
            provider: 'generic-hmac',
            method: 'POST',
            path: '/webhook',
            headers: ['X-Signature' => 'bad-signature'],
            rawBody: '{"event":"order.created"}',
        ));

        $this->assertFalse($result->verified);
        $this->assertSame('Invalid HMAC signature.', $result->message);
    }

    public function test_it_signs_payload_and_reports_capabilities(): void
    {
        $provider = new GenericHmacProvider('X-Signature', 'secret');
        $headers = $provider->sign('payload', ['Content-Type' => 'application/json']);

        $this->assertTrue($provider->canSign());
        $this->assertSame(hash_hmac('sha256', 'payload', 'secret'), $headers['X-Signature']);
        $this->assertSame('application/json', $headers['Content-Type']);
        $this->assertContains(ProviderCapability::VerifiesSignature, $provider->capabilities());
        $this->assertContains(ProviderCapability::ReSignsPayload, $provider->capabilities());
    }
}

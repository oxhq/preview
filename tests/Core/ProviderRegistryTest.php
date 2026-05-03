<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Core;

use InvalidArgumentException;
use Oxhq\Preview\Capture\PreviewRequest;
use Oxhq\Preview\Core\ProviderRegistry;
use Oxhq\Preview\Providers\GenericHmacProvider;
use Oxhq\Preview\Providers\GenericProvider;
use PHPUnit\Framework\TestCase;

final class ProviderRegistryTest extends TestCase
{
    public function test_it_applies_runtime_context_to_contextual_providers(): void
    {
        $registry = new ProviderRegistry();
        $registry->register(new GenericHmacProvider('X-Signature', 'secret'));
        $body = '{"event":"order.created"}';

        $provider = $registry->get('hmac', [
            'signature_header' => 'X-Custom-Signature',
            'algorithm' => 'sha512',
        ]);

        $this->assertTrue($provider->verify(PreviewRequest::make(
            provider: 'hmac',
            method: 'POST',
            path: '/webhook',
            headers: ['X-Custom-Signature' => hash_hmac('sha512', $body, 'secret')],
            rawBody: $body,
        ))->verified);
        $this->assertFalse($provider->verify(PreviewRequest::make(
            provider: 'hmac',
            method: 'POST',
            path: '/webhook',
            headers: ['X-Signature' => hash_hmac('sha256', $body, 'secret')],
            rawBody: $body,
        ))->verified);
    }

    public function test_it_returns_non_contextual_providers_unchanged_when_context_is_given(): void
    {
        $registry = new ProviderRegistry();
        $provider = new GenericProvider();
        $registry->register($provider);

        $this->assertSame($provider, $registry->get('generic', [
            'signature_header' => 'X-Ignored-Signature',
        ]));
    }

    public function test_it_reports_unknown_providers_when_context_is_given(): void
    {
        $registry = new ProviderRegistry();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown preview provider [missing].');

        $registry->get('missing', [
            'signature_header' => 'X-Signature',
        ]);
    }
}

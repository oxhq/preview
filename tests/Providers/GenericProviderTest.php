<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Providers;

use Oxhq\Preview\Providers\GenericProvider;
use Oxhq\Preview\Providers\ProviderCapability;
use Oxhq\Preview\Capture\PreviewRequest;
use PHPUnit\Framework\TestCase;

final class GenericProviderTest extends TestCase
{
    public function test_it_exposes_fixture_and_test_generation_capabilities(): void
    {
        $provider = new GenericProvider();

        $this->assertSame('generic', $provider->name());
        $this->assertSame([
            ProviderCapability::ExtractsEventType,
            ProviderCapability::GeneratesFixture,
            ProviderCapability::GeneratesTest,
        ], $provider->capabilities());
        $this->assertFalse($provider->canSign());
    }

    public function test_it_extracts_generic_event_type_and_fixture_name_from_header(): void
    {
        $provider = new GenericProvider();
        $request = PreviewRequest::make(
            provider: 'generic',
            method: 'POST',
            path: '/webhook',
            headers: ['X-Preview-Event' => 'Order Created'],
        );

        $this->assertSame('Order Created', $provider->eventType($request));
        $this->assertSame('order-created', $provider->fixtureName($request));
    }

    public function test_it_uses_generic_capture_fixture_name_without_event_type(): void
    {
        $provider = new GenericProvider();

        $this->assertSame('generic-capture', $provider->fixtureName(PreviewRequest::make(
            provider: 'generic',
            method: 'POST',
            path: '/webhook',
        )));
    }
}

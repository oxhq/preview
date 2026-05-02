<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests;

use Oxhq\Preview\Core\CaptureId;
use Oxhq\Preview\Core\ProviderRegistry;
use Oxhq\Preview\Core\RedactionPolicy;
use Oxhq\Preview\Core\Transport\TransportRegistry;

class FoundationTest extends TestCase
{
    public function test_it_registers_core_services(): void
    {
        $this->assertInstanceOf(CaptureId::class, app(CaptureId::class));
        $this->assertInstanceOf(RedactionPolicy::class, app(RedactionPolicy::class));
        $this->assertInstanceOf(ProviderRegistry::class, app(ProviderRegistry::class));
        $this->assertInstanceOf(TransportRegistry::class, app(TransportRegistry::class));
    }

    public function test_redaction_policy_redacts_sensitive_headers(): void
    {
        $policy = new RedactionPolicy(['authorization', 'cookie']);

        $headers = $policy->redactHeaders([
            'Authorization' => 'Bearer secret',
            'X-Event' => 'kept',
            'Cookie' => 'session=secret',
        ]);

        $this->assertSame('[redacted]', $headers['Authorization']);
        $this->assertSame('kept', $headers['X-Event']);
        $this->assertSame('[redacted]', $headers['Cookie']);
    }
}

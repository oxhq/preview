<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Core\Transport;

use InvalidArgumentException;
use Oxhq\Preview\Core\Transport\TransportRegistry;
use Oxhq\Preview\Core\Transport\TunnelHandle;
use Oxhq\Preview\Core\Transport\TunnelTransport;
use PHPUnit\Framework\TestCase;

final class TransportRegistryTest extends TestCase
{
    public function test_it_returns_a_registered_transport_by_name(): void
    {
        $transport = new NullTunnelTransport();
        $registry = new TransportRegistry();

        $registry->register('cloudflare', $transport);

        $this->assertSame($transport, $registry->get('cloudflare'));
    }

    public function test_it_rejects_unknown_transports(): void
    {
        $registry = new TransportRegistry();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown tunnel transport [missing].');

        $registry->get('missing');
    }
}

final class NullTunnelTransport implements TunnelTransport
{
    public function open(string $localUrl): TunnelHandle
    {
        return new TunnelHandle($localUrl);
    }

    public function close(TunnelHandle $handle): void
    {
    }
}

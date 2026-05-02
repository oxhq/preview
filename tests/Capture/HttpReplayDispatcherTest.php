<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Capture;

use Oxhq\Preview\Capture\HttpReplayDispatcher;
use Oxhq\Preview\Capture\ReplayResult;
use PHPUnit\Framework\TestCase;

final class HttpReplayDispatcherTest extends TestCase
{
    public function test_it_dispatches_payloads_to_a_target_base_url_with_payload_path_and_query(): void
    {
        $requests = [];
        $dispatcher = new HttpReplayDispatcher(function (string $url, string $method, array $headers, string $body, array $payload) use (&$requests): ReplayResult {
            $requests[] = compact('url', 'method', 'headers', 'body', 'payload');

            return new ReplayResult(202, 'accepted', ['X-Replayed' => ['1']]);
        });

        $result = $dispatcher->dispatch([
            'method' => 'POST',
            'path' => '/webhooks/orders',
            'query' => ['attempt' => '1'],
            'headers' => ['X-Captured' => 'yes'],
            'raw_body' => '{"id":1}',
        ], 'https://example.test/?base=1');

        $this->assertTrue($result->successful());
        $this->assertSame(202, $result->statusCode);
        $this->assertSame('https://example.test/webhooks/orders?base=1&attempt=1', $requests[0]['url']);
        $this->assertSame('POST', $requests[0]['method']);
        $this->assertSame(['X-Captured: yes'], $requests[0]['headers']);
        $this->assertSame('{"id":1}', $requests[0]['body']);
    }

    public function test_it_uses_a_full_target_url_without_payload_path_or_query_rewrite(): void
    {
        $requests = [];
        $dispatcher = new HttpReplayDispatcher(function (string $url, string $method, array $headers, string $body, array $payload) use (&$requests): ReplayResult {
            $requests[] = compact('url', 'method', 'headers', 'body', 'payload');

            return new ReplayResult(200, 'ok');
        });

        $dispatcher->dispatch([
            'method' => 'POST',
            'path' => '/captured/path',
            'query' => ['captured' => '1'],
            'headers' => [],
            'raw_body' => '',
        ], 'https://example.test/manual/endpoint?fixed=1');

        $this->assertSame('https://example.test/manual/endpoint?fixed=1', $requests[0]['url']);
    }
}

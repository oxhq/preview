<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\TestingCommands;

use DateTimeImmutable;
use Oxhq\Preview\Capture\CaptureRecord;
use Oxhq\Preview\Testing\FixtureWriter;
use PHPUnit\Framework\TestCase;

final class FixtureWriterTest extends TestCase
{
    public function test_it_writes_provider_aware_fixtures_without_committed_auth_or_cookie_headers(): void
    {
        $root = sys_get_temp_dir().'/preview-fixtures-'.bin2hex(random_bytes(4));
        $body = $root.'/capture-body.raw';
        mkdir($root, 0775, true);
        file_put_contents($body, '{"id":1}');

        $record = new CaptureRecord(
            id: 'cap_1',
            provider: 'stripe',
            eventType: 'checkout.session.completed',
            method: 'POST',
            path: '/webhook/stripe',
            query: [],
            headers: [
                'Authorization' => 'Bearer secret',
                'Cookie' => 'session=secret',
                'Set-Cookie' => 'session=secret',
                'Stripe-Signature' => 'old',
            ],
            rawBodyPath: $body,
            capturedAt: new DateTimeImmutable(),
            verified: true,
            metadata: ['fixture_name' => 'checkout-session-completed'],
        );

        $writer = new FixtureWriter($root.'/fixtures');
        $writer->write($record, providerCanSign: true);

        $headers = file_get_contents($root.'/fixtures/stripe/checkout-session-completed/headers.php');
        $fixture = file_get_contents($root.'/fixtures/stripe/checkout-session-completed/fixture.php');

        $this->assertStringNotContainsString('Authorization', (string) $headers);
        $this->assertStringNotContainsString('Cookie', (string) $headers);
        $this->assertStringNotContainsString('Set-Cookie', (string) $headers);
        $this->assertStringContainsString('Stripe-Signature', (string) $headers);
        $this->assertStringContainsString("->signing('resign')", (string) $fixture);
    }
}

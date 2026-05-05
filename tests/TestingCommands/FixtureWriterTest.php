<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\TestingCommands;

use DateTimeImmutable;
use Oxhq\Preview\Capture\CaptureRecord;
use Oxhq\Preview\Testing\FixtureWriter;
use Oxhq\Preview\Testing\PreviewFixture;
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
        $manifest = json_decode((string) file_get_contents($writer->manifestPath($record)), true);
        $loaded = PreviewFixture::load($root.'/fixtures/stripe/checkout-session-completed/fixture.php');

        $this->assertStringNotContainsString('Authorization', (string) $headers);
        $this->assertStringNotContainsString('Cookie', (string) $headers);
        $this->assertStringNotContainsString('Set-Cookie', (string) $headers);
        $this->assertStringContainsString('Stripe-Signature', (string) $headers);
        $this->assertStringContainsString("->signing('resign')", (string) $fixture);
        $this->assertStringContainsString('fixtureContext', (string) $fixture);
        $this->assertFileDoesNotExist($root.'/fixtures/stripe/checkout-session-completed/payload.json');
        $this->assertFileExists($root.'/fixtures/.local/stripe/checkout-session-completed/payload.json');
        $this->assertSame('{"id":1}', $loaded->rawBody());
        $this->assertSame([
            'capture_id' => 'cap_1',
            'provider' => 'stripe',
            'event_type' => 'checkout.session.completed',
            'method' => 'POST',
            'endpoint' => '/webhook/stripe',
            'signing' => 'resign',
            'fixture_context' => [],
            'payload' => [
                'local_only' => true,
            ],
            'headers' => [
                'Stripe-Signature' => 'old',
            ],
            'redacted_headers' => [
                'Authorization',
                'Cookie',
                'Set-Cookie',
            ],
        ], $manifest);
    }

    public function test_it_writes_hmac_fixture_context_without_shared_secret(): void
    {
        $root = sys_get_temp_dir().'/preview-fixtures-'.bin2hex(random_bytes(4));
        $body = $root.'/capture-body.raw';
        mkdir($root, 0775, true);
        file_put_contents($body, '{"id":1}');

        $record = new CaptureRecord(
            id: 'cap_hmac',
            provider: 'hmac',
            eventType: 'event.created',
            method: 'POST',
            path: '/webhook/hmac',
            query: [],
            headers: ['X-Custom-Signature' => 'old'],
            rawBodyPath: $body,
            capturedAt: new DateTimeImmutable(),
            verified: true,
            metadata: [
                'fixture_name' => 'event-created',
                'fixture_context' => [
                    'signature_header' => 'X-Custom-Signature',
                    'algorithm' => 'sha256',
                ],
            ],
        );

        $writer = new FixtureWriter($root.'/fixtures');
        $writer->write($record, providerCanSign: true);

        $fixture = file_get_contents($root.'/fixtures/hmac/event-created/fixture.php');
        $manifest = json_decode((string) file_get_contents($writer->manifestPath($record)), true);

        $this->assertStringContainsString("'signature_header' => 'X-Custom-Signature'", (string) $fixture);
        $this->assertStringContainsString("'algorithm' => 'sha256'", (string) $fixture);
        $this->assertStringNotContainsString('secret', (string) $fixture);
        $this->assertSame([
            'signature_header' => 'X-Custom-Signature',
            'algorithm' => 'sha256',
        ], PreviewFixture::load($root.'/fixtures/hmac/event-created/fixture.php')->fixtureContext());
        $this->assertSame([
            'signature_header' => 'X-Custom-Signature',
            'algorithm' => 'sha256',
        ], $manifest['fixture_context']);
    }

    public function test_it_keeps_payload_commit_ready_when_no_sensitive_headers_are_present(): void
    {
        $root = sys_get_temp_dir().'/preview-fixtures-'.bin2hex(random_bytes(4));
        $body = $root.'/capture-body.raw';
        mkdir($root, 0775, true);
        file_put_contents($body, '{"id":1}');

        $record = new CaptureRecord(
            id: 'cap_generic',
            provider: 'generic',
            eventType: 'event.created',
            method: 'POST',
            path: '/webhook/generic',
            query: [],
            headers: ['X-Preview-Event' => 'event.created'],
            rawBodyPath: $body,
            capturedAt: new DateTimeImmutable(),
            verified: true,
            metadata: ['fixture_name' => 'event-created'],
        );

        $writer = new FixtureWriter($root.'/fixtures');
        $writer->write($record);

        $manifest = json_decode((string) file_get_contents($writer->manifestPath($record)), true);

        $this->assertFileExists($root.'/fixtures/generic/event-created/payload.json');
        $this->assertFileDoesNotExist($root.'/fixtures/.local/generic/event-created/payload.json');
        $this->assertSame('{"id":1}', PreviewFixture::load($root.'/fixtures/generic/event-created/fixture.php')->rawBody());
        $this->assertSame([
            'capture_id' => 'cap_generic',
            'provider' => 'generic',
            'event_type' => 'event.created',
            'method' => 'POST',
            'endpoint' => '/webhook/generic',
            'signing' => 'exact',
            'fixture_context' => [],
            'payload' => [
                'local_only' => false,
            ],
            'headers' => [
                'X-Preview-Event' => 'event.created',
            ],
            'redacted_headers' => [],
        ], $manifest);
    }

    public function test_it_lists_configured_redacted_header_names_without_secret_values_in_manifest(): void
    {
        $root = sys_get_temp_dir().'/preview-fixtures-'.bin2hex(random_bytes(4));
        $body = $root.'/capture-body.raw';
        mkdir($root, 0775, true);
        file_put_contents($body, '{"id":1}');

        $record = new CaptureRecord(
            id: 'cap_redacted',
            provider: 'generic',
            eventType: 'event.created',
            method: 'POST',
            path: '/webhook/generic',
            query: [],
            headers: [
                'X-Api-Key' => 'secret-value',
                'X-Preview-Event' => 'event.created',
            ],
            rawBodyPath: $body,
            capturedAt: new DateTimeImmutable(),
            verified: true,
            metadata: ['fixture_name' => 'event-created'],
        );

        $writer = new FixtureWriter($root.'/fixtures', new \Oxhq\Preview\Core\RedactionPolicy(['X-Api-Key']));
        $writer->write($record);

        $manifestContents = (string) file_get_contents($writer->manifestPath($record));
        $manifest = json_decode($manifestContents, true);

        $this->assertStringNotContainsString('secret-value', $manifestContents);
        $this->assertSame([
            'X-Preview-Event' => 'event.created',
        ], $manifest['headers']);
        $this->assertSame(['X-Api-Key'], $manifest['redacted_headers']);
    }

    public function test_it_gitignores_local_only_fixture_payloads_inside_git_roots(): void
    {
        $root = sys_get_temp_dir().'/preview-fixtures-git-'.bin2hex(random_bytes(4));
        $body = $root.'/capture-body.raw';
        mkdir($root.'/.git', 0775, true);
        file_put_contents($root.'/.gitignore', "/vendor/\n");
        file_put_contents($body, '{"id":1}');

        $record = new CaptureRecord(
            id: 'cap_sensitive',
            provider: 'generic',
            eventType: 'event.created',
            method: 'POST',
            path: '/webhook/generic',
            query: [],
            headers: ['Authorization' => 'Bearer secret'],
            rawBodyPath: $body,
            capturedAt: new DateTimeImmutable(),
            verified: true,
            metadata: ['fixture_name' => 'event-created'],
        );

        try {
            $writer = new FixtureWriter($root.'/tests/Fixtures/Preview');
            $writer->write($record);

            $gitignore = str_replace("\r\n", "\n", (string) file_get_contents($root.'/.gitignore'));

            $this->assertStringContainsString("/tests/Fixtures/Preview/.local/\n", $gitignore);
            $this->assertFileExists($root.'/tests/Fixtures/Preview/.local/generic/event-created/payload.json');
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}

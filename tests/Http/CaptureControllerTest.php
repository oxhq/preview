<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Http;

use Oxhq\Preview\Capture\CaptureRepository;
use Oxhq\Preview\Tests\TestCase;

final class CaptureControllerTest extends TestCase
{
    public function test_it_captures_inbound_http_requests_for_a_provider(): void
    {
        $response = $this->call(
            'POST',
            '/__preview/capture/generic?from=http',
            [],
            [],
            [],
            [
                'HTTP_X_PREVIEW_ORIGINAL_PATH' => '/webhooks/orders',
                'HTTP_X_PREVIEW_EVENT' => 'order.created',
                'CONTENT_TYPE' => 'application/json',
            ],
            '{"id":1}',
        );

        $response
            ->assertOk()
            ->assertJson([
                'provider' => 'generic',
                'event_type' => 'order.created',
                'verified' => false,
                'verification_message' => 'Generic provider does not verify request signatures.',
            ])
            ->assertJsonMissingPath('raw_body')
            ->assertJsonMissingPath('headers')
            ->assertJsonMissingPath('query');

        $id = (string) $response->json('id');
        $record = app(CaptureRepository::class)->find($id);

        $this->assertSame('generic', $record->provider);
        $this->assertSame('POST', $record->method);
        $this->assertSame('/webhooks/orders', $record->path);
        $this->assertSame(['from' => 'http'], $record->query);
        $this->assertSame('{"id":1}', $record->rawBody());
    }

    public function test_it_returns_404_json_for_unknown_providers(): void
    {
        $response = $this->post('/__preview/capture/missing', [], [
            'X-Preview-Original-Path' => '/webhooks/missing',
        ]);

        $response
            ->assertNotFound()
            ->assertJson([
                'error' => 'Unknown preview provider [missing].',
            ]);

        $this->assertSame([], app(CaptureRepository::class)->all());
    }

    public function test_hmac_capture_endpoint_can_use_signature_header_from_capture_url_query(): void
    {
        $body = '{"id":1}';
        $signature = hash_hmac('sha256', $body, 'test-secret');

        $response = $this->call(
            'POST',
            '/__preview/capture/hmac?signature_header=X-Custom-Signature',
            [],
            [],
            [],
            [
                'HTTP_X_PREVIEW_ORIGINAL_PATH' => '/webhooks/hmac',
                'HTTP_X_CUSTOM_SIGNATURE' => $signature,
                'CONTENT_TYPE' => 'application/json',
            ],
            $body,
        );

        $response
            ->assertOk()
            ->assertJson([
                'provider' => 'hmac',
                'verified' => true,
                'verification_message' => null,
            ]);

        $record = app(CaptureRepository::class)->find((string) $response->json('id'));

        $this->assertSame('hmac', $record->provider);
        $this->assertSame('/webhooks/hmac', $record->path);
        $this->assertSame([
            'signature_header' => 'X-Custom-Signature',
            'algorithm' => 'sha256',
        ], $record->metadata['fixture_context']);
    }

    public function test_hmac_capture_endpoint_uses_configured_signature_header_without_query_override(): void
    {
        $body = '{"id":1}';
        $signature = hash_hmac('sha256', $body, 'test-secret');

        $response = $this->call(
            'POST',
            '/__preview/capture/hmac',
            [],
            [],
            [],
            [
                'HTTP_X_PREVIEW_ORIGINAL_PATH' => '/webhooks/hmac',
                'HTTP_X_SIGNATURE' => $signature,
                'CONTENT_TYPE' => 'application/json',
            ],
            $body,
        );

        $response
            ->assertOk()
            ->assertJson([
                'provider' => 'hmac',
                'verified' => true,
                'verification_message' => null,
            ]);
    }

    public function test_hmac_capture_endpoint_reports_missing_query_configured_signature_header(): void
    {
        $body = '{"id":1}';
        $signature = hash_hmac('sha256', $body, 'test-secret');

        $response = $this->call(
            'POST',
            '/__preview/capture/hmac?signature_header=X-Custom-Signature',
            [],
            [],
            [],
            [
                'HTTP_X_PREVIEW_ORIGINAL_PATH' => '/webhooks/hmac',
                'HTTP_X_SIGNATURE' => $signature,
                'CONTENT_TYPE' => 'application/json',
            ],
            $body,
        );

        $response
            ->assertOk()
            ->assertJson([
                'provider' => 'hmac',
                'verified' => false,
                'verification_message' => 'Missing X-Custom-Signature header.',
            ]);
    }


    public function test_it_redacts_sensitive_headers_when_storing_captures(): void
    {
        $response = $this->call(
            'POST',
            '/__preview/capture/generic',
            [],
            [],
            [],
            [
                'HTTP_X_PREVIEW_ORIGINAL_PATH' => '/webhooks/redaction',
                'HTTP_X_PREVIEW_EVENT' => 'redaction.checked',
                'HTTP_AUTHORIZATION' => 'Bearer secret-token',
                'HTTP_COOKIE' => 'session=secret',
                'HTTP_X_PUBLIC_HEADER' => 'kept',
            ],
            '{"secret":"body is stored but not returned"}',
        );

        $response
            ->assertOk()
            ->assertJsonMissing([
                'Bearer secret-token',
                'session=secret',
                'body is stored but not returned',
            ]);

        $record = app(CaptureRepository::class)->find((string) $response->json('id'));

        $this->assertSame('[redacted]', $record->headers['authorization']);
        $this->assertSame('[redacted]', $record->headers['cookie']);
        $this->assertSame(['kept'], $record->headers['x-public-header']);
    }
}

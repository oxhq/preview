<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\TestingCommands;

use Oxhq\Preview\Testing\PreviewFixture;
use Oxhq\Preview\Tests\TestCase;

final class PreviewFixtureTest extends TestCase
{
    public function test_hmac_fixture_context_controls_fresh_signed_header_name_without_storing_secret(): void
    {
        $root = sys_get_temp_dir().'/preview-fixture-runtime-'.bin2hex(random_bytes(4));
        $body = $root.'/payload.json';
        mkdir($root, 0775, true);
        file_put_contents($body, '{"id":1}');

        $fixture = PreviewFixture::provider('hmac')
            ->fixtureContext([
                'signature_header' => 'X-Custom-Signature',
                'algorithm' => 'sha256',
            ])
            ->rawBody($body);

        $headers = $fixture->freshSignedHeaders();

        $this->assertArrayHasKey('X-Custom-Signature', $headers);
        $this->assertArrayNotHasKey('X-Signature', $headers);
        $this->assertSame(hash_hmac('sha256', '{"id":1}', 'test-secret'), $headers['X-Custom-Signature']);
    }

    public function test_hmac_fixture_context_controls_fresh_signed_header_algorithm(): void
    {
        $root = sys_get_temp_dir().'/preview-fixture-algorithm-'.bin2hex(random_bytes(4));
        $body = $root.'/payload.json';
        mkdir($root, 0775, true);
        file_put_contents($body, '{"id":1}');

        $fixture = PreviewFixture::provider('hmac')
            ->fixtureContext([
                'signature_header' => 'X-Custom-Signature',
                'algorithm' => 'sha512',
            ])
            ->rawBody($body);

        $headers = $fixture->freshSignedHeaders();

        $this->assertSame(hash_hmac('sha512', '{"id":1}', 'test-secret'), $headers['X-Custom-Signature']);
    }
}

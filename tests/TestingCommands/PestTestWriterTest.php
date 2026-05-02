<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\TestingCommands;

use DateTimeImmutable;
use Oxhq\Preview\Capture\CaptureRecord;
use Oxhq\Preview\Testing\FixtureWriter;
use Oxhq\Preview\Testing\PestTestWriter;
use PHPUnit\Framework\TestCase;

final class PestTestWriterTest extends TestCase
{
    public function test_it_generates_pest_tests_that_use_fresh_signed_headers_when_the_provider_can_sign(): void
    {
        $root = sys_get_temp_dir().'/preview-pest-'.bin2hex(random_bytes(4));
        $body = $root.'/capture-body.raw';
        mkdir($root, 0775, true);
        file_put_contents($body, '{"type":"event.created"}');

        $record = new CaptureRecord(
            id: 'cap_2',
            provider: 'signed',
            eventType: 'event.created',
            method: 'POST',
            path: '/webhook',
            query: [],
            headers: ['X-Test' => 'yes'],
            rawBodyPath: $body,
            capturedAt: new DateTimeImmutable(),
            verified: true,
            metadata: ['fixture_name' => 'event-created'],
        );

        $writer = new PestTestWriter($root.'/Feature', new FixtureWriter($root.'/Fixtures'));
        $path = $writer->write($record, providerCanSign: true);
        $contents = file_get_contents($path);

        $this->assertStringContainsString('$fixture->freshSignedHeaders()', (string) $contents);
        $this->assertStringContainsString('PreviewFixture::load', (string) $contents);
        $this->assertStringContainsString('__DIR__', (string) $contents);
        $this->assertStringContainsString('/../../Fixtures/signed/event-created/fixture.php', str_replace('\\', '/', (string) $contents));
        $this->assertStringNotContainsString(str_replace('\\', '/', $root), str_replace('\\', '/', (string) $contents));
    }
}

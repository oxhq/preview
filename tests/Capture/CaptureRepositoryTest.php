<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Capture;

use Oxhq\Preview\Capture\CaptureRepository;
use Oxhq\Preview\Capture\PreviewRequest;
use Oxhq\Preview\Providers\GenericProvider;
use PHPUnit\Framework\TestCase;

final class CaptureRepositoryTest extends TestCase
{
    public function test_it_stores_metadata_json_separately_from_the_raw_body(): void
    {
        $root = sys_get_temp_dir().'/preview-captures-'.bin2hex(random_bytes(4));
        $repository = new CaptureRepository($root);

        $record = $repository->store(
            PreviewRequest::make('generic', 'post', '/webhooks/orders', ['a' => '1'], ['X-Preview-Event' => 'Order Created'], '{"id":1}'),
            new GenericProvider(),
        );

        $this->assertSame('{"id":1}', $record->rawBody());
        $this->assertFileExists($root.'/'.$record->id.'/metadata.json');
        $this->assertFileExists($root.'/'.$record->id.'/body.raw');
        $this->assertSame('Order Created', $repository->find($record->id)->eventType);
        $this->assertCount(1, $repository->all());
    }
}

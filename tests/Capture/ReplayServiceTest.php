<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Capture;

use Oxhq\Preview\Capture\CaptureRepository;
use Oxhq\Preview\Capture\PreviewRequest;
use Oxhq\Preview\Capture\ReplayService;
use Oxhq\Preview\Core\ProviderRegistry;
use Oxhq\Preview\Core\RedactionPolicy;
use Oxhq\Preview\Providers\GenericHmacProvider;
use Oxhq\Preview\Providers\GenericProvider;
use RuntimeException;
use PHPUnit\Framework\TestCase;

final class ReplayServiceTest extends TestCase
{
    public function test_it_returns_exact_replay_payloads_and_fails_clearly_when_resigning_is_unsupported(): void
    {
        $root = sys_get_temp_dir().'/preview-replay-'.bin2hex(random_bytes(4));
        $repository = new CaptureRepository($root, new RedactionPolicy(['authorization', 'cookie']));
        $record = $repository->store(
            PreviewRequest::make(
                'generic',
                'POST',
                '/webhook',
                [],
                [
                    'X-Captured' => '1',
                    'Authorization' => 'Bearer secret',
                    'Cookie' => 'session=secret',
                ],
                '{"ok":true}',
            ),
            new GenericProvider(),
        );
        $registry = new ProviderRegistry();
        $registry->register(new GenericProvider());
        $service = new ReplayService($repository, $registry);

        $payload = $service->exact($record);

        $this->assertSame('1', $payload['headers']['X-Captured']);
        $this->assertSame('Bearer secret', $payload['headers']['Authorization']);
        $this->assertSame('session=secret', $payload['headers']['Cookie']);
        $this->assertSame('[redacted]', $record->headers['Authorization']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Use --exact instead');

        $service->resign($record);
    }

    public function test_it_uses_provider_signing_headers_for_resign_replay_payloads(): void
    {
        $root = sys_get_temp_dir().'/preview-replay-sign-'.bin2hex(random_bytes(4));
        $provider = new GenericHmacProvider('X-Signature', 'secret');
        $repository = new CaptureRepository($root, new RedactionPolicy(['authorization', 'cookie']));
        $record = $repository->store(
            PreviewRequest::make(
                'generic-hmac',
                'POST',
                '/webhook',
                [],
                [
                    'X-Captured' => '1',
                    'Authorization' => 'Bearer secret',
                ],
                '{"ok":true}',
            ),
            $provider,
        );
        $registry = new ProviderRegistry();
        $registry->register($provider);
        $service = new ReplayService($repository, $registry);

        $this->assertArrayHasKey('X-Signature', $service->resign($record)['headers']);
        $this->assertSame('1', $service->resign($record)['headers']['X-Captured']);
        $this->assertSame('Bearer secret', $service->resign($record)['headers']['Authorization']);
        $this->assertSame('[redacted]', $record->headers['Authorization']);
    }
}

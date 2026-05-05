<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Capture;

use DateTimeImmutable;
use Oxhq\Preview\Capture\CaptureRepository;
use Oxhq\Preview\Capture\PreviewRequest;
use Oxhq\Preview\Core\RedactionPolicy;
use Oxhq\Preview\Providers\GenericHmacProvider;
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
        $this->assertFileExists($root.'/'.$record->id.'/headers.raw.json');
        $this->assertSame([], $record->metadata['fixture_context']);
        $this->assertSame('Order Created', $repository->find($record->id)->eventType);
        $this->assertCount(1, $repository->all());
    }

    public function test_it_keeps_raw_headers_local_while_metadata_headers_are_redacted(): void
    {
        $root = sys_get_temp_dir().'/preview-captures-'.bin2hex(random_bytes(4));
        $repository = new CaptureRepository($root, new RedactionPolicy(['authorization', 'cookie']));

        $record = $repository->store(
            PreviewRequest::make(
                'generic',
                'post',
                '/webhooks/orders',
                [],
                [
                    'Authorization' => 'Bearer secret',
                    'Cookie' => 'session=secret',
                    'X-Preview-Event' => 'Order Created',
                ],
                '{"id":1}',
            ),
            new GenericProvider(),
        );

        $loaded = $repository->find($record->id);

        $this->assertSame('[redacted]', $loaded->headers['Authorization']);
        $this->assertSame('[redacted]', $loaded->headers['Cookie']);
        $this->assertSame('Bearer secret', $loaded->rawHeaders()['Authorization']);
        $this->assertSame('session=secret', $loaded->rawHeaders()['Cookie']);
    }

    public function test_it_stores_provider_fixture_context_in_capture_metadata(): void
    {
        $root = sys_get_temp_dir().'/preview-captures-'.bin2hex(random_bytes(4));
        $repository = new CaptureRepository($root);

        $record = $repository->store(
            PreviewRequest::make('hmac', 'post', '/webhooks/hmac', [], [], '{"id":1}'),
            new GenericHmacProvider('X-Custom-Signature', 'secret', 'sha512'),
        );

        $this->assertSame([
            'signature_header' => 'X-Custom-Signature',
            'algorithm' => 'sha512',
        ], $repository->find($record->id)->metadata['fixture_context']);
    }

    public function test_it_prunes_captures_older_than_the_cutoff(): void
    {
        $root = sys_get_temp_dir().'/preview-captures-'.bin2hex(random_bytes(4));
        $repository = new CaptureRepository($root);
        $provider = new GenericProvider();

        $old = $repository->store(
            new PreviewRequest('generic', 'POST', '/old', [], [], '{}', new DateTimeImmutable('2025-12-31T23:59:59+00:00')),
            $provider,
        );
        $onCutoff = $repository->store(
            new PreviewRequest('generic', 'POST', '/cutoff', [], [], '{}', new DateTimeImmutable('2026-01-01T00:00:00+00:00')),
            $provider,
        );
        $new = $repository->store(
            new PreviewRequest('generic', 'POST', '/new', [], [], '{}', new DateTimeImmutable('2026-01-02T00:00:00+00:00')),
            $provider,
        );

        $pruned = $repository->pruneBefore(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        $this->assertSame([$old->id], array_map(fn ($record): string => $record->id, $pruned));
        $this->assertDirectoryDoesNotExist($root.'/'.$old->id);
        $this->assertDirectoryExists($root.'/'.$onCutoff->id);
        $this->assertDirectoryExists($root.'/'.$new->id);
    }

    public function test_dry_run_prune_lists_matching_captures_without_deleting(): void
    {
        $root = sys_get_temp_dir().'/preview-captures-'.bin2hex(random_bytes(4));
        $repository = new CaptureRepository($root);
        $provider = new GenericProvider();

        $old = $repository->store(
            new PreviewRequest('generic', 'POST', '/old', [], [], '{}', new DateTimeImmutable('2025-12-31T23:59:59+00:00')),
            $provider,
        );
        $repository->store(
            new PreviewRequest('generic', 'POST', '/new', [], [], '{}', new DateTimeImmutable('2026-01-02T00:00:00+00:00')),
            $provider,
        );

        $pruned = $repository->pruneBefore(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), dryRun: true);

        $this->assertSame([$old->id], array_map(fn ($record): string => $record->id, $pruned));
        $this->assertDirectoryExists($root.'/'.$old->id);
        $this->assertCount(2, $repository->all());
    }

    public function test_it_appends_gitignore_rule_for_capture_storage_inside_git_root(): void
    {
        $root = sys_get_temp_dir().'/preview-git-root-'.bin2hex(random_bytes(4));
        mkdir($root.'/.git', 0775, true);
        file_put_contents($root.'/.gitignore', "/vendor/\n");

        $repository = new CaptureRepository($root.'/storage/framework/preview/captures');

        try {
            $repository->store(
                PreviewRequest::make('generic', 'post', '/webhooks/orders', [], [], '{"id":1}'),
                new GenericProvider(),
            );

            $contents = str_replace("\r\n", "\n", (string) file_get_contents($root.'/.gitignore'));

            $this->assertStringContainsString("/storage/framework/preview/captures/\n", $contents);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_it_does_not_append_gitignore_rule_when_parent_path_is_already_ignored(): void
    {
        $root = sys_get_temp_dir().'/preview-git-root-'.bin2hex(random_bytes(4));
        mkdir($root.'/.git', 0775, true);
        file_put_contents($root.'/.gitignore', "/storage/framework/preview/\n");

        $repository = new CaptureRepository($root.'/storage/framework/preview/captures');

        try {
            $repository->store(
                PreviewRequest::make('generic', 'post', '/webhooks/orders', [], [], '{"id":1}'),
                new GenericProvider(),
            );

            $this->assertSame(
                "/storage/framework/preview/\n",
                (string) file_get_contents($root.'/.gitignore'),
            );
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

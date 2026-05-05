<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Oxhq\Preview\Capture\CaptureRepository;
use Oxhq\Preview\Commands\CaptureExportCommand;
use Oxhq\Preview\Tests\TestCase;

final class CaptureExportCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(Kernel::class)->registerCommand($this->app->make(CaptureExportCommand::class));
    }

    public function test_it_exports_redacted_metadata_without_raw_payload_or_raw_headers(): void
    {
        $exportRoot = sys_get_temp_dir().'/preview-tests/exports';

        $this->artisan('preview:capture', [
            'provider' => 'generic',
            '--path' => '/webhooks/orders',
            '--body' => '{"secret":"do-not-export"}',
            '--header' => ['X-Preview-Event: order.created', 'Authorization: Bearer export-secret'],
            '--query' => ['include=full'],
        ])->assertExitCode(0);

        $record = app(CaptureRepository::class)->all()[0];

        $this->artisan('preview:capture:export', [
            'capture' => $record->id,
            '--path' => $exportRoot,
        ])
            ->expectsOutputToContain("Exported capture [{$record->id}]")
            ->expectsOutputToContain('Raw payload and raw headers were not exported.')
            ->assertExitCode(0);

        $exportPath = $exportRoot.DIRECTORY_SEPARATOR.$record->id;
        $metadataPath = $exportPath.DIRECTORY_SEPARATOR.'metadata.json';

        $this->assertFileExists($metadataPath);
        $this->assertFileDoesNotExist($exportPath.DIRECTORY_SEPARATOR.'body.raw');
        $this->assertFileDoesNotExist($exportPath.DIRECTORY_SEPARATOR.'headers.raw.json');

        $metadata = json_decode((string) file_get_contents($metadataPath), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($record->id, $metadata['id']);
        $this->assertSame('generic', $metadata['provider']);
        $this->assertSame('order.created', $metadata['event_type']);
        $this->assertSame('POST', $metadata['method']);
        $this->assertSame('/webhooks/orders', $metadata['path']);
        $this->assertSame(['include' => 'full'], $metadata['query']);
        $this->assertSame('[redacted]', $metadata['headers']['Authorization']);
        $this->assertSame($record->capturedAt->format(DATE_ATOM), $metadata['captured_at']);
        $this->assertFalse($metadata['verified']);
        $this->assertArrayHasKey('verification_message', $metadata);
        $this->assertSame($record->metadata, $metadata['metadata']);
        $this->assertArrayNotHasKey('raw_body_path', $metadata);
        $this->assertArrayNotHasKey('raw_headers_path', $metadata);
        $this->assertStringNotContainsString('do-not-export', (string) file_get_contents($metadataPath));
        $this->assertStringNotContainsString('export-secret', (string) file_get_contents($metadataPath));
    }

    public function test_json_output_reports_export_details_without_raw_files(): void
    {
        $exportRoot = sys_get_temp_dir().'/preview-tests/json-exports';

        $this->artisan('preview:capture', [
            'provider' => 'generic',
            '--path' => '/webhooks/orders',
            '--body' => '{"id":1}',
        ])->assertExitCode(0);

        $record = app(CaptureRepository::class)->all()[0];
        $exitCode = Artisan::call('preview:capture:export', [
            'capture' => $record->id,
            '--path' => $exportRoot,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($record->id, $payload['capture_id']);
        $this->assertSame(['metadata.json'], $payload['files']);
        $this->assertFalse($payload['raw_included']);
        $this->assertSame(
            str_replace('\\', '/', $exportRoot.'/'.$record->id),
            str_replace('\\', '/', $payload['export_path']),
        );
        $this->assertStringNotContainsString('{"id":1}', Artisan::output());
    }

    public function test_it_uses_a_safe_capture_id_segment_for_export_directory(): void
    {
        $exportRoot = sys_get_temp_dir().'/preview-tests/safe-exports';

        $this->artisan('preview:capture', [
            'provider' => 'generic',
            '--path' => '/webhooks/orders',
        ])->assertExitCode(0);

        $record = app(CaptureRepository::class)->all()[0];
        $metadataPath = app(CaptureRepository::class)->metadataFilePath($record->id);
        $metadata = json_decode((string) file_get_contents($metadataPath), true, flags: JSON_THROW_ON_ERROR);
        $metadata['id'] = '..';
        file_put_contents($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $this->artisan('preview:capture:export', [
            'capture' => $record->id,
            '--path' => $exportRoot,
        ])->assertExitCode(0);

        $this->assertFileExists($exportRoot.DIRECTORY_SEPARATOR.'capture'.DIRECTORY_SEPARATOR.'metadata.json');
        $this->assertFileDoesNotExist(dirname($exportRoot).DIRECTORY_SEPARATOR.$record->id.DIRECTORY_SEPARATOR.'metadata.json');
    }

    public function test_it_gitignores_export_root(): void
    {
        $repoRoot = sys_get_temp_dir().'/preview-tests/repo';
        $exportRoot = $repoRoot.'/storage/preview/exports';

        mkdir($repoRoot.'/.git', 0775, true);

        $this->artisan('preview:capture', [
            'provider' => 'generic',
            '--path' => '/webhooks/orders',
        ])->assertExitCode(0);

        $record = app(CaptureRepository::class)->all()[0];

        $this->artisan('preview:capture:export', [
            'capture' => $record->id,
            '--path' => $exportRoot,
        ])->assertExitCode(0);

        $this->assertStringContainsString('/storage/preview/exports/', (string) file_get_contents($repoRoot.'/.gitignore'));
    }

    public function test_it_fails_when_capture_is_missing(): void
    {
        $this->artisan('preview:capture:export', [
            'capture' => 'missing-capture',
            '--path' => sys_get_temp_dir().'/preview-tests/missing-exports',
        ])
            ->expectsOutputToContain('Capture [missing-capture] was not found.')
            ->assertExitCode(1);
    }
}

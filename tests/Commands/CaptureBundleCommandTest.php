<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Oxhq\Preview\Capture\CaptureRepository;
use Oxhq\Preview\Commands\CaptureBundleCommand;
use Oxhq\Preview\Tests\TestCase;

final class CaptureBundleCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(Kernel::class)->registerCommand($this->app->make(CaptureBundleCommand::class));
    }

    public function test_it_writes_a_safe_metadata_only_bundle_by_default(): void
    {
        $bundleRoot = sys_get_temp_dir().'/preview-tests/bundles';

        $this->artisan('preview:capture', [
            'provider' => 'generic',
            '--path' => '/webhooks/orders',
            '--body' => '{"secret":"do-not-bundle"}',
            '--header' => ['X-Preview-Event: order.created', 'Authorization: Bearer bundle-secret'],
            '--query' => ['include=full'],
        ])->assertExitCode(0);

        $record = app(CaptureRepository::class)->all()[0];

        $this->artisan('preview:capture:bundle', [
            'capture' => $record->id,
            '--path' => $bundleRoot,
        ])
            ->expectsOutputToContain("Bundled capture [{$record->id}]")
            ->expectsOutputToContain('Raw payload and raw headers were not included.')
            ->assertExitCode(0);

        $bundlePath = $bundleRoot.DIRECTORY_SEPARATOR.$record->id;
        $metadataPath = $bundlePath.DIRECTORY_SEPARATOR.'metadata.json';

        $this->assertFileExists($metadataPath);
        $this->assertFileDoesNotExist($bundlePath.DIRECTORY_SEPARATOR.'body.raw');
        $this->assertFileDoesNotExist($bundlePath.DIRECTORY_SEPARATOR.'headers.raw.json');

        $metadataJson = (string) file_get_contents($metadataPath);
        $metadata = json_decode($metadataJson, true, flags: JSON_THROW_ON_ERROR);
        $rawHeadersJson = (string) file_get_contents($record->rawHeadersPath);

        $this->assertSame($record->id, $metadata['id']);
        $this->assertSame('generic', $metadata['provider']);
        $this->assertSame('order.created', $metadata['event_type']);
        $this->assertSame('POST', $metadata['method']);
        $this->assertSame('/webhooks/orders', $metadata['path']);
        $this->assertSame(['include' => 'full'], $metadata['query']);
        $this->assertSame(['Authorization', 'X-Preview-Event'], $metadata['header_names']);
        $this->assertSame(hash('sha256', '{"secret":"do-not-bundle"}'), $metadata['raw_body_sha256']);
        $this->assertSame(strlen('{"secret":"do-not-bundle"}'), $metadata['raw_body_bytes']);
        $this->assertSame(hash('sha256', $rawHeadersJson), $metadata['raw_headers_sha256']);
        $this->assertSame(strlen($rawHeadersJson), $metadata['raw_headers_bytes']);
        $this->assertFalse($metadata['raw_included']);
        $this->assertArrayNotHasKey('headers', $metadata);
        $this->assertArrayNotHasKey('raw_body_path', $metadata);
        $this->assertArrayNotHasKey('raw_headers_path', $metadata);
        $this->assertStringNotContainsString('do-not-bundle', $metadataJson);
        $this->assertStringNotContainsString('bundle-secret', $metadataJson);
    }

    public function test_it_includes_raw_body_and_headers_when_requested(): void
    {
        $bundleRoot = sys_get_temp_dir().'/preview-tests/raw-bundles';

        $this->artisan('preview:capture', [
            'provider' => 'generic',
            '--path' => '/webhooks/orders',
            '--body' => '{"secret":"bundle-me"}',
            '--header' => ['X-Preview-Event: order.created', 'Authorization: Bearer raw-secret'],
        ])->assertExitCode(0);

        $record = app(CaptureRepository::class)->all()[0];

        $this->artisan('preview:capture:bundle', [
            'capture' => $record->id,
            '--path' => $bundleRoot,
            '--include-raw' => true,
        ])->assertExitCode(0);

        $bundlePath = $bundleRoot.DIRECTORY_SEPARATOR.$record->id;
        $metadata = json_decode(
            (string) file_get_contents($bundlePath.DIRECTORY_SEPARATOR.'metadata.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertTrue($metadata['raw_included']);
        $this->assertSame('{"secret":"bundle-me"}', (string) file_get_contents($bundlePath.DIRECTORY_SEPARATOR.'body.raw'));

        $headers = json_decode(
            (string) file_get_contents($bundlePath.DIRECTORY_SEPARATOR.'headers.raw.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('Bearer raw-secret', $headers['Authorization']);
        $this->assertSame(['metadata.json', 'body.raw', 'headers.raw.json'], $metadata['files']);
    }

    public function test_json_output_reports_bundle_details(): void
    {
        $bundleRoot = sys_get_temp_dir().'/preview-tests/json-bundles';

        $this->artisan('preview:capture', [
            'provider' => 'generic',
            '--path' => '/webhooks/orders',
            '--body' => '{"id":1}',
        ])->assertExitCode(0);

        $record = app(CaptureRepository::class)->all()[0];
        $exitCode = Artisan::call('preview:capture:bundle', [
            'capture' => $record->id,
            '--path' => $bundleRoot,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($record->id, $payload['capture_id']);
        $this->assertSame(['metadata.json'], $payload['files']);
        $this->assertFalse($payload['raw_included']);
        $this->assertSame(
            str_replace('\\', '/', $bundleRoot.'/'.$record->id),
            str_replace('\\', '/', $payload['bundle_path']),
        );
        $this->assertStringNotContainsString('{"id":1}', Artisan::output());
    }

    public function test_it_uses_configured_export_path_when_path_is_not_given(): void
    {
        $bundleRoot = sys_get_temp_dir().'/preview-tests/configured-bundles';
        config(['preview.export_path' => $bundleRoot]);

        $this->artisan('preview:capture', [
            'provider' => 'generic',
            '--path' => '/webhooks/orders',
        ])->assertExitCode(0);

        $record = app(CaptureRepository::class)->all()[0];

        $this->artisan('preview:capture:bundle', [
            'capture' => $record->id,
        ])->assertExitCode(0);

        $this->assertFileExists($bundleRoot.DIRECTORY_SEPARATOR.$record->id.DIRECTORY_SEPARATOR.'metadata.json');
    }

    public function test_it_uses_a_safe_capture_id_segment_for_bundle_directory(): void
    {
        $bundleRoot = sys_get_temp_dir().'/preview-tests/safe-bundles';

        $this->artisan('preview:capture', [
            'provider' => 'generic',
            '--path' => '/webhooks/orders',
        ])->assertExitCode(0);

        $record = app(CaptureRepository::class)->all()[0];
        $metadataPath = app(CaptureRepository::class)->metadataFilePath($record->id);
        $metadata = json_decode((string) file_get_contents($metadataPath), true, flags: JSON_THROW_ON_ERROR);
        $metadata['id'] = '..';
        file_put_contents($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $this->artisan('preview:capture:bundle', [
            'capture' => $record->id,
            '--path' => $bundleRoot,
        ])->assertExitCode(0);

        $this->assertFileExists($bundleRoot.DIRECTORY_SEPARATOR.'capture'.DIRECTORY_SEPARATOR.'metadata.json');
        $this->assertFileDoesNotExist(dirname($bundleRoot).DIRECTORY_SEPARATOR.$record->id.DIRECTORY_SEPARATOR.'metadata.json');
    }

    public function test_it_gitignores_bundle_root(): void
    {
        $repoRoot = sys_get_temp_dir().'/preview-tests/repo';
        $bundleRoot = $repoRoot.'/storage/preview/exports';

        mkdir($repoRoot.'/.git', 0775, true);

        $this->artisan('preview:capture', [
            'provider' => 'generic',
            '--path' => '/webhooks/orders',
        ])->assertExitCode(0);

        $record = app(CaptureRepository::class)->all()[0];

        $this->artisan('preview:capture:bundle', [
            'capture' => $record->id,
            '--path' => $bundleRoot,
        ])->assertExitCode(0);

        $this->assertStringContainsString('/storage/preview/exports/', (string) file_get_contents($repoRoot.'/.gitignore'));
    }

    public function test_it_fails_when_capture_is_missing(): void
    {
        $this->artisan('preview:capture:bundle', [
            'capture' => 'missing-capture',
            '--path' => sys_get_temp_dir().'/preview-tests/missing-bundles',
        ])
            ->expectsOutputToContain('Capture [missing-capture] was not found.')
            ->assertExitCode(1);
    }
}

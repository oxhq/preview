<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Oxhq\Preview\Capture\CaptureRepository;
use Oxhq\Preview\Commands\CaptureIntegrityCommand;
use Oxhq\Preview\Tests\TestCase;

final class CaptureIntegrityCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(Kernel::class)->registerCommand($this->app->make(CaptureIntegrityCommand::class));
    }

    public function test_json_output_reports_hashes_and_byte_counts_without_raw_payloads_or_headers(): void
    {
        $this->artisan('preview:capture', [
            'provider' => 'generic',
            '--path' => '/webhooks/orders',
            '--body' => '{"secret":"do-not-print"}',
            '--header' => ['X-Preview-Event: order.created', 'Authorization: Bearer header-secret'],
        ])->assertExitCode(0);

        $record = app(CaptureRepository::class)->all()[0];
        $metadataPath = app(CaptureRepository::class)->metadataFilePath($record->id);

        $exitCode = Artisan::call('preview:capture:integrity', [
            'capture' => $record->id,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $output = Artisan::output();
        $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($record->id, $payload['capture_id']);
        $this->assertTrue($payload['ok']);
        $this->assertSame([], $payload['errors']);
        $this->assertSame([
            'metadata',
            'raw_body',
            'raw_headers',
        ], array_keys($payload['files']));

        $this->assertFileSummary($metadataPath, $payload['files']['metadata']);
        $this->assertFileSummary($record->rawBodyPath, $payload['files']['raw_body']);
        $this->assertFileSummary((string) $record->rawHeadersPath, $payload['files']['raw_headers']);

        $this->assertStringNotContainsString('do-not-print', $output);
        $this->assertStringNotContainsString('header-secret', $output);
        $this->assertStringNotContainsString('Bearer header-secret', $output);
        $this->assertStringNotContainsString('{"secret":"do-not-print"}', $output);
    }

    public function test_it_fails_when_a_raw_capture_file_is_missing(): void
    {
        $this->artisan('preview:capture', [
            'provider' => 'generic',
            '--path' => '/webhooks/orders',
            '--body' => '{"id":1}',
        ])->assertExitCode(0);

        $record = app(CaptureRepository::class)->all()[0];
        unlink($record->rawBodyPath);

        $exitCode = Artisan::call('preview:capture:integrity', [
            'capture' => $record->id,
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($record->id, $payload['capture_id']);
        $this->assertFalse($payload['ok']);
        $this->assertSame('Raw body file could not be read.', $payload['errors'][0]);
        $this->assertFalse($payload['files']['raw_body']['exists']);
        $this->assertFalse($payload['files']['raw_body']['readable']);
        $this->assertNull($payload['files']['raw_body']['bytes']);
        $this->assertNull($payload['files']['raw_body']['sha256']);
        $this->assertStringNotContainsString('{"id":1}', Artisan::output());
    }

    public function test_human_output_reports_success_without_raw_payloads_or_headers(): void
    {
        $this->artisan('preview:capture', [
            'provider' => 'generic',
            '--path' => '/webhooks/orders',
            '--body' => 'raw-human-secret',
            '--header' => ['Authorization: Bearer human-header-secret'],
        ])->assertExitCode(0);

        $record = app(CaptureRepository::class)->all()[0];

        $this->artisan('preview:capture:integrity', [
            'capture' => $record->id,
        ])
            ->expectsOutputToContain("Capture [{$record->id}] integrity passed.")
            ->expectsOutputToContain('metadata:')
            ->expectsOutputToContain('raw_body:')
            ->expectsOutputToContain('raw_headers:')
            ->assertExitCode(0);

        $output = Artisan::output();

        $this->assertStringNotContainsString('raw-human-secret', $output);
        $this->assertStringNotContainsString('human-header-secret', $output);
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function assertFileSummary(string $path, array $summary): void
    {
        $this->assertSame($path, $summary['path']);
        $this->assertTrue($summary['exists']);
        $this->assertTrue($summary['readable']);
        $this->assertSame(filesize($path), $summary['bytes']);
        $this->assertSame(hash_file('sha256', $path), $summary['sha256']);
    }
}

<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use Illuminate\Support\Facades\Artisan;
use Oxhq\Preview\Capture\CaptureRepository;
use Oxhq\Preview\Core\ProviderRegistry;
use Oxhq\Preview\Tests\TestCase;

final class CaptureDoctorCommandTest extends TestCase
{
    public function test_it_reports_healthy_captures_as_valid_json_without_raw_content_or_secrets(): void
    {
        $this->artisan('preview:capture', [
            'provider' => 'generic',
            '--path' => '/webhooks/orders',
            '--body' => '{"secret":"body-value"}',
            '--header' => ['Authorization: Bearer metadata-secret', 'X-Preview-Event: order.created'],
        ])->assertExitCode(0);

        $record = app(CaptureRepository::class)->all()[0];
        $exitCode = Artisan::call('preview:capture:doctor', [
            '--capture' => $record->id,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $rows = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(1, $rows);
        $this->assertSame($record->id, $rows[0]['id']);
        $this->assertSame('generic', $rows[0]['provider']);
        $this->assertTrue($rows[0]['valid']);
        $this->assertSame([], $rows[0]['errors']);
        $this->assertSame([], $rows[0]['warnings']);
        $this->assertArrayNotHasKey('raw_body', $rows[0]);
        $this->assertArrayNotHasKey('raw_body_path', $rows[0]);
        $this->assertArrayNotHasKey('raw_headers', $rows[0]);
        $this->assertArrayNotHasKey('raw_headers_path', $rows[0]);
        $this->assertStringNotContainsString('body-value', Artisan::output());
        $this->assertStringNotContainsString('metadata-secret', Artisan::output());
        $this->assertStringNotContainsString('Bearer metadata-secret', Artisan::output());
    }

    public function test_it_reports_internal_consistency_failures_and_exits_non_zero(): void
    {
        $this->artisan('preview:capture', [
            'provider' => 'generic',
            '--path' => '/webhooks/orders',
            '--body' => '{"secret":"body-value"}',
            '--header' => ['Authorization: Bearer metadata-secret'],
        ])->assertExitCode(0);

        $record = app(CaptureRepository::class)->all()[0];
        $metadataPath = dirname($record->rawBodyPath).DIRECTORY_SEPARATOR.'metadata.json';
        $metadata = json_decode((string) file_get_contents($metadataPath), true, flags: JSON_THROW_ON_ERROR);
        $metadata['provider'] = 'missing-provider';
        $metadata['headers']['Authorization'] = 'Bearer metadata-secret';
        file_put_contents($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        unlink($record->rawBodyPath);
        file_put_contents((string) $record->rawHeadersPath, '{not-json');

        $exitCode = Artisan::call('preview:capture:doctor', [
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);

        $rows = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(1, $rows);
        $this->assertSame($record->id, $rows[0]['id']);
        $this->assertSame('missing-provider', $rows[0]['provider']);
        $this->assertFalse($rows[0]['valid']);
        $this->assertContains('Provider [missing-provider] is not registered.', $rows[0]['errors']);
        $this->assertContains('Raw body file could not be read.', $rows[0]['errors']);
        $this->assertContains('Raw headers file could not be decoded.', $rows[0]['errors']);
        $this->assertContains('Metadata header [Authorization] contains unredacted sensitive data.', $rows[0]['errors']);
        $this->assertSame([], $rows[0]['warnings']);
        $this->assertStringNotContainsString('body-value', Artisan::output());
        $this->assertStringNotContainsString('metadata-secret', Artisan::output());
    }

    public function test_it_reports_unreadable_metadata_rows_instead_of_skipping_them(): void
    {
        $root = (string) config('preview.storage_path');
        $badDirectory = $root.DIRECTORY_SEPARATOR.'bad-capture';

        mkdir($badDirectory, 0775, true);
        file_put_contents($badDirectory.DIRECTORY_SEPARATOR.'metadata.json', '{not-json');

        $exitCode = Artisan::call('preview:capture:doctor', [
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);

        $rows = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([[
            'id' => 'bad-capture',
            'provider' => null,
            'valid' => false,
            'errors' => ['Metadata could not be decoded.'],
            'warnings' => [],
        ]], $rows);
    }

    public function test_capture_option_limits_diagnostics_to_one_capture(): void
    {
        $this->artisan('preview:capture', [
            'provider' => 'generic',
            '--path' => '/first',
            '--body' => '{}',
        ])->assertExitCode(0);
        $first = app(CaptureRepository::class)->all()[0];

        $this->artisan('preview:capture', [
            'provider' => 'generic',
            '--path' => '/second',
            '--body' => '{}',
        ])->assertExitCode(0);
        $second = collect(app(CaptureRepository::class)->all())
            ->firstOrFail(fn ($record): bool => $record->id !== $first->id);

        $exitCode = Artisan::call('preview:capture:doctor', [
            '--capture' => $first->id,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $rows = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([$first->id], array_column($rows, 'id'));
        $this->assertNotContains($second->id, array_column($rows, 'id'));
    }

    public function test_it_fails_clearly_when_a_specific_capture_does_not_exist(): void
    {
        $this->app->instance(ProviderRegistry::class, new ProviderRegistry());

        $this->artisan('preview:capture:doctor', [
            '--capture' => 'missing-capture',
        ])
            ->expectsOutput('Capture [missing-capture] was not found.')
            ->assertExitCode(1);
    }
}

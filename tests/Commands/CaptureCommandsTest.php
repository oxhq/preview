<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use Oxhq\Preview\Capture\CaptureRepository;
use Oxhq\Preview\Tests\TestCase;

final class CaptureCommandsTest extends TestCase
{
    public function test_capture_command_rejects_unknown_provider(): void
    {
        $this->artisan('preview:capture', ['provider' => 'missing'])
            ->expectsOutputToContain('Unknown preview provider [missing].')
            ->assertExitCode(1);
    }

    public function test_hmac_capture_requires_signature_header_option(): void
    {
        $this->artisan('preview:capture', [
            'provider' => 'hmac',
            '--path' => '/webhook',
        ])
            ->expectsOutput('The hmac provider requires --signature-header.')
            ->assertExitCode(1);
    }

    public function test_capture_list_show_replay_fixture_and_test_commands_work_together(): void
    {
        $this->artisan('preview:capture', [
            'provider' => 'generic',
            '--path' => '/webhooks/orders',
            '--body' => '{"id":1}',
            '--header' => ['X-Preview-Event: order.created'],
        ])
            ->expectsOutputToContain('Captured')
            ->expectsOutputToContain('Endpoint: POST /webhooks/orders')
            ->assertExitCode(0);

        $record = app(CaptureRepository::class)->all()[0];
        $this->assertSame('generic', $record->provider);
        $this->assertSame('order.created', $record->eventType);

        $this->artisan('preview:capture:list')
            ->assertExitCode(0);

        $this->artisan('preview:capture:show', ['capture' => $record->id])
            ->assertExitCode(0);

        $this->artisan('preview:capture:replay', [
            'capture' => $record->id,
            '--exact' => true,
        ])
            ->expectsOutputToContain('Replay payload ready')
            ->expectsOutputToContain('Endpoint: POST /webhooks/orders')
            ->assertExitCode(0);

        $this->artisan('preview:capture:fixture', ['capture' => $record->id])
            ->expectsOutputToContain('Fixture generated')
            ->assertExitCode(0);

        $this->artisan('preview:capture:test', ['capture' => $record->id])
            ->expectsOutputToContain('Pest test generated')
            ->assertExitCode(0);
    }
}

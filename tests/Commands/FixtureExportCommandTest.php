<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Oxhq\Preview\Tests\TestCase;

final class FixtureExportCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::assertTrue(class_exists('Oxhq\\Preview\\Commands\\FixtureExportCommand'));

        $this->app->make(Kernel::class)->registerCommand(
            new \Oxhq\Preview\Commands\FixtureExportCommand(),
        );
    }

    public function test_it_exports_a_commit_ready_fixture_without_printing_payload_or_header_values(): void
    {
        $fixtureRoot = (string) config('preview.fixture_path');
        $exportRoot = sys_get_temp_dir().'/preview-tests/fixture-exports';

        $this->writeFixture($fixtureRoot, 'generic', 'order-created', [
            'capture_id' => 'cap_generic_order',
            'provider' => 'generic',
            'event_type' => 'order.created',
            'method' => 'POST',
            'endpoint' => '/webhooks/orders',
            'signing' => 'exact',
            'payload' => [
                'local_only' => false,
            ],
            'headers' => [
                'X-Preview-Event' => 'header-secret-must-not-print',
            ],
        ], payloadBody: 'payload-secret-must-not-print');

        $exitCode = Artisan::call('preview:fixture:export', [
            'capture_id' => 'cap_generic_order',
            '--path' => $exportRoot,
            '--json' => true,
        ]);
        $output = Artisan::output();

        self::assertSame(0, $exitCode);
        self::assertStringNotContainsString('payload-secret-must-not-print', $output);
        self::assertStringNotContainsString('header-secret-must-not-print', $output);

        $details = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $exportPath = $exportRoot.DIRECTORY_SEPARATOR.'cap_generic_order';

        self::assertSame('cap_generic_order', $details['capture_id']);
        self::assertTrue($details['payload_copied']);
        self::assertSame(
            str_replace('\\', '/', $exportPath),
            str_replace('\\', '/', $details['export_path']),
        );
        self::assertSame([
            'manifest.json',
            'fixture.php',
            'headers.php',
            'payload.json',
        ], $details['files']);

        self::assertFileExists($exportPath.DIRECTORY_SEPARATOR.'manifest.json');
        self::assertFileExists($exportPath.DIRECTORY_SEPARATOR.'fixture.php');
        self::assertFileExists($exportPath.DIRECTORY_SEPARATOR.'headers.php');
        self::assertFileExists($exportPath.DIRECTORY_SEPARATOR.'payload.json');
        self::assertSame(
            'payload-secret-must-not-print',
            file_get_contents($exportPath.DIRECTORY_SEPARATOR.'payload.json'),
        );
    }

    public function test_it_skips_local_only_payloads_and_reports_payload_copied_false(): void
    {
        $fixtureRoot = (string) config('preview.fixture_path');
        $exportRoot = sys_get_temp_dir().'/preview-tests/local-only-fixture-exports';

        $this->writeFixture($fixtureRoot, 'stripe', 'checkout', [
            'capture_id' => 'cap_stripe_checkout',
            'provider' => 'stripe',
            'event_type' => 'checkout.session.completed',
            'method' => 'POST',
            'endpoint' => '/stripe/webhook',
            'signing' => 'resign',
            'payload' => [
                'local_only' => true,
            ],
            'headers' => [
                'Stripe-Signature' => 'local-header-secret-must-not-print',
            ],
        ], payloadBody: 'local-payload-secret-must-not-print', localOnly: true);

        $this->artisan('preview:fixture:export', [
            'capture_id' => 'cap_stripe_checkout',
            '--path' => $exportRoot,
        ])
            ->expectsOutputToContain('Exported fixture [cap_stripe_checkout].')
            ->expectsOutputToContain('Payload copied: no')
            ->assertExitCode(0);

        $output = Artisan::output();
        $exportPath = $exportRoot.DIRECTORY_SEPARATOR.'cap_stripe_checkout';

        self::assertStringNotContainsString('local-payload-secret-must-not-print', $output);
        self::assertStringNotContainsString('local-header-secret-must-not-print', $output);
        self::assertFileExists($exportPath.DIRECTORY_SEPARATOR.'manifest.json');
        self::assertFileExists($exportPath.DIRECTORY_SEPARATOR.'fixture.php');
        self::assertFileExists($exportPath.DIRECTORY_SEPARATOR.'headers.php');
        self::assertFileDoesNotExist($exportPath.DIRECTORY_SEPARATOR.'payload.json');
    }

    public function test_it_uses_a_safe_capture_id_segment_for_the_export_directory(): void
    {
        $fixtureRoot = (string) config('preview.fixture_path');
        $exportRoot = sys_get_temp_dir().'/preview-tests/safe-fixture-exports';

        $this->writeFixture($fixtureRoot, 'generic', 'unsafe', [
            'capture_id' => '..',
            'provider' => 'generic',
            'event_type' => 'unsafe.created',
            'method' => 'POST',
            'endpoint' => '/unsafe',
            'signing' => 'exact',
            'payload' => [
                'local_only' => false,
            ],
        ], payloadBody: '{"id":1}');

        $this->artisan('preview:fixture:export', [
            'capture_id' => '..',
            '--path' => $exportRoot,
        ])->assertExitCode(0);

        self::assertFileExists($exportRoot.DIRECTORY_SEPARATOR.'fixture'.DIRECTORY_SEPARATOR.'manifest.json');
        self::assertFileDoesNotExist(dirname($exportRoot).DIRECTORY_SEPARATOR.'manifest.json');
    }

    public function test_it_fails_when_fixture_manifest_is_missing(): void
    {
        $this->artisan('preview:fixture:export', [
            'capture_id' => 'missing-capture',
            '--path' => sys_get_temp_dir().'/preview-tests/missing-fixture-exports',
        ])
            ->expectsOutputToContain('Fixture manifest for capture [missing-capture] was not found.')
            ->assertExitCode(1);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function writeFixture(
        string $fixtureRoot,
        string $provider,
        string $name,
        array $manifest,
        string $payloadBody,
        bool $localOnly = false,
    ): void {
        $directory = $fixtureRoot.'/'.$provider.'/'.$name;
        $this->ensureDirectory($directory);
        file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($directory.'/fixture.php', "<?php\n\nreturn 'fixture';\n");
        file_put_contents($directory.'/headers.php', "<?php\n\nreturn ['X-Secret' => 'header-secret-must-not-print'];\n");

        if ($localOnly) {
            $payloadPath = $fixtureRoot.'/.local/'.$provider.'/'.$name.'/payload.json';
            $this->ensureDirectory(dirname($payloadPath));
            file_put_contents($payloadPath, $payloadBody);

            return;
        }

        file_put_contents($directory.'/payload.json', $payloadBody);
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0775, true);
        }
    }
}

<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Oxhq\Preview\Commands\FixtureDoctorCommand;
use Oxhq\Preview\Tests\TestCase;

final class FixtureDoctorCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(Kernel::class)->registerCommand(new FixtureDoctorCommand());
    }

    public function test_it_outputs_stable_json_diagnostics_without_printing_payloads_or_headers(): void
    {
        $fixtureRoot = (string) config('preview.fixture_path');

        $this->writeFixture($fixtureRoot, 'generic', 'valid', [
            'capture_id' => 'cap_valid',
            'provider' => 'generic',
            'event_type' => 'order.created',
            'method' => 'POST',
            'endpoint' => '/webhooks/orders',
            'signing' => 'exact',
            'payload' => [
                'local_only' => false,
            ],
            'headers' => [
                'Authorization' => 'Bearer must-not-print',
            ],
        ], payloadLocalOnly: false);

        $this->writeFixture($fixtureRoot, 'stripe', 'local-only', [
            'capture_id' => 'cap_local',
            'provider' => 'stripe',
            'event_type' => null,
            'method' => 'POST',
            'endpoint' => '/stripe/webhook',
            'signing' => 'resign',
            'payload' => [
                'local_only' => true,
            ],
        ], payloadLocalOnly: true);

        $exitCode = Artisan::call('preview:fixture:doctor', [
            '--json' => true,
        ]);
        $output = Artisan::output();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString("\n    ", $output);
        self::assertStringNotContainsString('payload-secret', $output);
        self::assertStringNotContainsString('header-secret', $output);
        self::assertStringNotContainsString('must-not-print', $output);

        $rows = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame([
            [
                'path' => 'generic/valid/manifest.json',
                'valid' => true,
                'capture_id' => 'cap_valid',
                'provider' => 'generic',
                'event_type' => 'order.created',
                'endpoint' => '/webhooks/orders',
                'signing' => 'exact',
                'payload_local_only' => false,
                'issues' => [],
            ],
            [
                'path' => 'stripe/local-only/manifest.json',
                'valid' => true,
                'capture_id' => 'cap_local',
                'provider' => 'stripe',
                'event_type' => null,
                'endpoint' => '/stripe/webhook',
                'signing' => 'resign',
                'payload_local_only' => true,
                'issues' => [],
            ],
        ], $rows);
    }

    public function test_it_reports_manifest_and_companion_file_issues_but_still_succeeds(): void
    {
        $fixtureRoot = (string) config('preview.fixture_path');

        $this->writeFixture($fixtureRoot, 'generic', 'missing-payload', [
            'capture_id' => 'cap_missing_payload',
            'provider' => 'generic',
            'event_type' => 'missing.payload',
            'method' => 'POST',
            'endpoint' => '/webhooks/missing',
            'signing' => 'exact',
            'payload' => [
                'local_only' => false,
            ],
        ], payloadLocalOnly: false);
        unlink($fixtureRoot.'/generic/missing-payload/payload.json');

        $missingFieldsPath = $fixtureRoot.'/generic/missing-fields/manifest.json';
        $invalidJsonPath = $fixtureRoot.'/stripe/broken-json/manifest.json';
        $this->ensureDirectory(dirname($missingFieldsPath));
        $this->ensureDirectory(dirname($invalidJsonPath));
        file_put_contents($missingFieldsPath, json_encode([
            'capture_id' => 'cap_missing_fields',
            'provider' => 'generic',
            'payload' => [
                'local_only' => 'no',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($invalidJsonPath, '{"capture_id":');

        $exitCode = Artisan::call('preview:fixture:doctor', [
            '--json' => true,
        ]);
        $rows = $this->rowsByPath(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR));

        self::assertSame(0, $exitCode);
        self::assertFalse($rows['generic/missing-payload/manifest.json']['valid']);
        self::assertSame(['Missing expected payload.json.'], $rows['generic/missing-payload/manifest.json']['issues']);
        self::assertFalse($rows['generic/missing-fields/manifest.json']['valid']);
        self::assertContains('Missing or invalid required field method.', $rows['generic/missing-fields/manifest.json']['issues']);
        self::assertContains('Missing or invalid required field endpoint.', $rows['generic/missing-fields/manifest.json']['issues']);
        self::assertContains('Missing or invalid required field signing.', $rows['generic/missing-fields/manifest.json']['issues']);
        self::assertContains('Missing or invalid required field payload.local_only.', $rows['generic/missing-fields/manifest.json']['issues']);
        self::assertContains('Missing companion fixture.php.', $rows['generic/missing-fields/manifest.json']['issues']);
        self::assertContains('Missing companion headers.php.', $rows['generic/missing-fields/manifest.json']['issues']);
        self::assertFalse($rows['stripe/broken-json/manifest.json']['valid']);
        self::assertSame('stripe/broken-json/manifest.json', $rows['stripe/broken-json/manifest.json']['path']);
        self::assertStringContainsString('Manifest JSON is invalid', $rows['stripe/broken-json/manifest.json']['issues'][0]);
    }

    public function test_it_summarizes_and_lists_issue_rows_as_text(): void
    {
        $fixtureRoot = (string) config('preview.fixture_path');

        $this->writeFixture($fixtureRoot, 'generic', 'valid', [
            'capture_id' => 'cap_valid',
            'provider' => 'generic',
            'event_type' => 'valid.created',
            'method' => 'POST',
            'endpoint' => '/valid',
            'signing' => 'exact',
            'payload' => [
                'local_only' => false,
            ],
        ], payloadLocalOnly: false);

        $manifestPath = $fixtureRoot.'/generic/broken/manifest.json';
        $this->ensureDirectory(dirname($manifestPath));
        file_put_contents($manifestPath, json_encode([
            'capture_id' => 'cap_broken',
            'provider' => 'generic',
            'method' => 'POST',
            'endpoint' => '/broken',
            'signing' => 'exact',
            'payload' => [
                'local_only' => false,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $exitCode = Artisan::call('preview:fixture:doctor');
        $output = str_replace('\\', '/', Artisan::output());

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Fixture manifest diagnostics: 2 manifests, 1 valid, 1 with issues.', $output);
        self::assertStringContainsString('generic/broken/manifest.json', $output);
        self::assertStringContainsString('cap_broken', $output);
        self::assertStringContainsString('Missing companion fixture.php.', $output);
        self::assertStringContainsString('Missing companion headers.php.', $output);
        self::assertStringContainsString('Missing expected payload.json.', $output);
        self::assertStringNotContainsString('generic/valid/manifest.json', $output);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function writeFixture(string $fixtureRoot, string $provider, string $name, array $manifest, bool $payloadLocalOnly): void
    {
        $directory = $fixtureRoot.'/'.$provider.'/'.$name;
        $this->ensureDirectory($directory);
        file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($directory.'/fixture.php', '<?php return null;'.PHP_EOL);
        file_put_contents($directory.'/headers.php', "<?php return ['X-Secret' => 'header-secret'];".PHP_EOL);

        if ($payloadLocalOnly) {
            $payloadPath = $fixtureRoot.'/.local/'.$provider.'/'.$name.'/payload.json';
            $this->ensureDirectory(dirname($payloadPath));
            file_put_contents($payloadPath, '{"secret":"payload-secret"}');

            return;
        }

        file_put_contents($directory.'/payload.json', '{"secret":"payload-secret"}');
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function rowsByPath(array $rows): array
    {
        $indexed = [];

        foreach ($rows as $row) {
            $indexed[(string) $row['path']] = $row;
        }

        return $indexed;
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0775, true);
        }
    }
}

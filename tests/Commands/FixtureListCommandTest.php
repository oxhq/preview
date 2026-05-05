<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use Illuminate\Support\Facades\Artisan;
use Oxhq\Preview\Tests\TestCase;

final class FixtureListCommandTest extends TestCase
{
    public function test_it_lists_fixture_manifests_without_body_or_secret_values(): void
    {
        $fixtureRoot = (string) config('preview.fixture_path');

        $this->writeManifest($fixtureRoot.'/generic/order-created/manifest.json', [
            'capture_id' => 'cap_generic',
            'provider' => 'generic',
            'event_type' => 'order.created',
            'endpoint' => '/webhooks/orders',
            'signing' => 'exact',
            'payload' => [
                'local_only' => false,
            ],
            'headers' => [
                'Authorization' => 'Bearer must-not-print',
            ],
            'raw_body' => '{"secret":"must-not-print"}',
        ]);

        $this->writeManifest($fixtureRoot.'/stripe/checkout/manifest.json', [
            'capture_id' => 'cap_stripe',
            'provider' => 'stripe',
            'event_type' => 'checkout.session.completed',
            'endpoint' => '/stripe/webhook',
            'signing' => 'resign',
            'payload' => [
                'local_only' => true,
            ],
        ]);

        $exitCode = Artisan::call('preview:fixture:list');
        $output = Artisan::output();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('cap_generic', $output);
        self::assertStringContainsString('generic', $output);
        self::assertStringContainsString('order.created', $output);
        self::assertStringContainsString('/webhooks/orders', $output);
        self::assertStringContainsString('exact', $output);
        self::assertStringContainsString('no', $output);
        self::assertStringContainsString('cap_stripe', $output);
        self::assertStringContainsString('yes', $output);
        self::assertStringNotContainsString('must-not-print', $output);
        self::assertStringNotContainsString('{"secret"', $output);
    }

    public function test_it_outputs_machine_readable_fixture_manifest_rows(): void
    {
        $fixtureRoot = (string) config('preview.fixture_path');

        $this->writeManifest($fixtureRoot.'/hmac/signed/manifest.json', [
            'capture_id' => 'cap_hmac',
            'provider' => 'hmac',
            'event_type' => null,
            'endpoint' => '/webhooks/signed',
            'signing' => 'resign',
            'payload' => [
                'local_only' => true,
            ],
            'headers' => [
                'X-Custom-Signature' => 'secret-signature-value',
            ],
        ]);

        $exitCode = Artisan::call('preview:fixture:list', [
            '--json' => true,
        ]);

        self::assertSame(0, $exitCode);

        $rows = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame([
            [
                'capture_id' => 'cap_hmac',
                'provider' => 'hmac',
                'event_type' => null,
                'endpoint' => '/webhooks/signed',
                'signing' => 'resign',
                'local_only' => true,
                'valid' => true,
            ],
        ], $rows);
        self::assertStringNotContainsString('secret-signature-value', Artisan::output());
    }

    public function test_it_reports_invalid_fixture_manifests_without_throwing(): void
    {
        $fixtureRoot = (string) config('preview.fixture_path');

        $this->writeManifest($fixtureRoot.'/generic/valid/manifest.json', [
            'capture_id' => 'cap_valid',
            'provider' => 'generic',
            'event_type' => 'valid.created',
            'endpoint' => '/valid',
            'signing' => 'exact',
            'payload' => [
                'local_only' => false,
            ],
        ]);

        $invalidJsonPath = $fixtureRoot.'/generic/broken-json/manifest.json';
        $invalidShapePath = $fixtureRoot.'/generic/broken-shape/manifest.json';
        $this->ensureDirectory(dirname($invalidJsonPath));
        $this->ensureDirectory(dirname($invalidShapePath));
        file_put_contents($invalidJsonPath, '{"capture_id":');
        file_put_contents($invalidShapePath, json_encode(['capture_id' => 'missing_fields']));

        $exitCode = Artisan::call('preview:fixture:list', [
            '--json' => true,
        ]);

        self::assertSame(0, $exitCode);

        $rows = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        self::assertCount(3, $rows);
        self::assertSame('cap_valid', $rows[0]['capture_id']);
        self::assertTrue($rows[0]['valid']);
        self::assertSame('invalid', $rows[1]['capture_id']);
        self::assertFalse($rows[1]['valid']);
        self::assertStringContainsString('broken-json/manifest.json', $rows[1]['manifest']);
        self::assertSame('invalid', $rows[2]['capture_id']);
        self::assertFalse($rows[2]['valid']);
        self::assertStringContainsString('broken-shape/manifest.json', $rows[2]['manifest']);

        $exitCode = Artisan::call('preview:fixture:list');
        $output = Artisan::output();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('invalid', $output);
        self::assertStringContainsString('broken-json/manifest.json', str_replace('\\', '/', $output));
        self::assertStringContainsString('broken-shape/manifest.json', str_replace('\\', '/', $output));
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function writeManifest(string $path, array $manifest): void
    {
        $this->ensureDirectory(dirname($path));
        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0775, true);
        }
    }
}

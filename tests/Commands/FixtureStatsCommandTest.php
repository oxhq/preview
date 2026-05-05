<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Oxhq\Preview\Tests\TestCase;

final class FixtureStatsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::assertTrue(class_exists('Oxhq\\Preview\\Commands\\FixtureStatsCommand'));

        $this->app->make(Kernel::class)->registerCommand(
            new \Oxhq\Preview\Commands\FixtureStatsCommand(),
        );
    }

    public function test_it_outputs_fixture_inventory_stats_as_json_without_payload_bodies(): void
    {
        $fixtureRoot = (string) config('preview.fixture_path');

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
        ], payloadBody: 'body-secret-must-not-print', writePayload: false);

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
                'Stripe-Signature' => 'header-secret-must-not-print',
            ],
        ], payloadBody: 'local-body-secret-must-not-print', localOnly: true);

        $this->writeFixture($fixtureRoot, 'generic', 'customer-updated', [
            'capture_id' => 'cap_generic_customer',
            'provider' => 'generic',
            'event_type' => 'customer.updated',
            'method' => 'POST',
            'endpoint' => '/webhooks/customers',
            'signing' => 'exact',
            'payload' => [
                'local_only' => false,
            ],
        ], payloadBody: 'another-body-secret-must-not-print');

        $invalidJsonPath = $fixtureRoot.'/generic/broken-json/manifest.json';
        $invalidShapePath = $fixtureRoot.'/github/missing-fields/manifest.json';
        $this->ensureDirectory(dirname($invalidJsonPath));
        $this->ensureDirectory(dirname($invalidShapePath));
        file_put_contents($invalidJsonPath, '{"capture_id":');
        file_put_contents($invalidShapePath, json_encode(['capture_id' => 'missing_fields']));

        $exitCode = Artisan::call('preview:fixture:stats', [
            '--json' => true,
        ]);
        $output = Artisan::output();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString("\n    ", $output);
        self::assertStringNotContainsString('body-secret-must-not-print', $output);
        self::assertStringNotContainsString('local-body-secret-must-not-print', $output);
        self::assertStringNotContainsString('another-body-secret-must-not-print', $output);
        self::assertStringNotContainsString('header-secret-must-not-print', $output);

        $stats = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame([
            'fixture_path' => str_replace('\\', '/', $fixtureRoot),
            'total_manifests' => 5,
            'total_fixtures' => 3,
            'invalid_manifest_count' => 2,
            'local_only_payload_count' => 1,
            'checked_in_payload_count' => 2,
            'providers' => [
                'generic' => 2,
                'stripe' => 1,
            ],
            'signing_modes' => [
                'exact' => 2,
                'resign' => 1,
            ],
        ], $stats);
    }

    public function test_it_outputs_human_readable_fixture_inventory_stats(): void
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
        ], payloadBody: 'body-secret-must-not-print');

        $brokenPath = $fixtureRoot.'/stripe/broken/manifest.json';
        $this->ensureDirectory(dirname($brokenPath));
        file_put_contents($brokenPath, '{"capture_id":');

        $exitCode = Artisan::call('preview:fixture:stats');
        $output = str_replace('\\', '/', Artisan::output());

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Fixture inventory:', $output);
        self::assertStringContainsString('Total manifests: 2', $output);
        self::assertStringContainsString('Total fixtures: 1', $output);
        self::assertStringContainsString('Invalid manifests: 1', $output);
        self::assertStringContainsString('Local-only payloads: 0', $output);
        self::assertStringContainsString('Checked-in payloads: 1', $output);
        self::assertStringContainsString('generic', $output);
        self::assertStringContainsString('exact', $output);
        self::assertStringNotContainsString('body-secret-must-not-print', $output);
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
        bool $writePayload = true,
    ): void {
        $directory = $fixtureRoot.'/'.$provider.'/'.$name;
        $this->ensureDirectory($directory);
        file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (! $writePayload) {
            return;
        }

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

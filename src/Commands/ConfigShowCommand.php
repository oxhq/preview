<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;

final class ConfigShowCommand extends Command
{
    protected $signature = 'preview:config {--json : Output redacted Preview config as JSON}';

    protected $description = 'Show redacted Laravel Preview configuration without printing secrets.';

    /** @var array<string, array{config_key: string, placeholder: string}> */
    private const PROVIDER_SECRET_CONFIG = [
        'hmac' => [
            'config_key' => 'preview.hmac.secret',
            'placeholder' => 'preview-secret',
        ],
        'github' => [
            'config_key' => 'preview.github.webhook_secret',
            'placeholder' => 'github-preview-secret',
        ],
        'shopify' => [
            'config_key' => 'preview.shopify.client_secret',
            'placeholder' => 'shopify-preview-secret',
        ],
        'stripe' => [
            'config_key' => 'preview.stripe.endpoint_secret',
            'placeholder' => 'whsec_preview',
        ],
    ];

    /** @var array<string, string> */
    private const TRANSPORT_BINARY_CONFIG = [
        'cloudflare' => 'preview.transport_binaries.cloudflare',
        'ngrok' => 'preview.transport_binaries.ngrok',
        'stripe_cli' => 'preview.transport_binaries.stripe_cli',
    ];

    public function handle(): int
    {
        $summary = $this->summary();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('Preview configuration (redacted; secrets are not printed):');
        $this->line('Storage path: '.$summary['storage_path']);
        $this->line('Fixture path: '.$summary['fixture_path']);
        $this->line('Test path: '.$summary['test_path']);
        $this->line('Scenario path: '.$summary['scenario_path']);
        $this->line('HTTP capture: '.($summary['http_capture']['enabled'] ? 'enabled' : 'disabled').' ('.$summary['http_capture']['path'].')');
        $this->line('Route preview: '.($summary['route_preview']['enabled'] ? 'enabled' : 'disabled').' ('.$summary['route_preview']['path'].')');
        $this->line('Live capture: '.($summary['live_enabled'] ? 'enabled' : 'disabled'));
        $this->line('Redacted headers: '.$this->listValue($summary['redacted_headers']));

        $this->line('Providers:');
        foreach ($summary['providers'] as $name => $class) {
            $this->line(sprintf(' - %s: %s', $name, $class));
        }

        $this->line('Provider secrets:');
        foreach ($summary['provider_secret_status'] as $name => $status) {
            $this->line(sprintf(' - %s: %s (%s)', $name, $status['status'], $status['config_key']));
        }

        $this->line('Transports:');
        foreach ($summary['transports'] as $name => $class) {
            $this->line(sprintf(' - %s: %s', $name, $class));
        }

        $this->line('Transport binaries:');
        foreach ($summary['transport_binary_status'] as $name => $status) {
            $binary = $status['binary'] === '' ? 'missing' : $status['binary'];
            $this->line(sprintf(' - %s: %s (configured: %s)', $name, $binary, $status['configured'] ? 'yes' : 'no'));
        }

        return self::SUCCESS;
    }

    /**
     * @return array{
     *     storage_path: string,
     *     fixture_path: string,
     *     test_path: string,
     *     scenario_path: string,
     *     http_capture: array{enabled: bool, path: string},
     *     route_preview: array{enabled: bool, path: string},
     *     live_enabled: bool,
     *     transports: array<string, string>,
     *     providers: array<string, string>,
     *     redacted_headers: list<string>,
     *     provider_secret_status: array<string, array{status: string, config_key: string}>,
     *     transport_binary_status: array<string, array{binary: string, configured: bool}>
     * }
     */
    private function summary(): array
    {
        return [
            'storage_path' => (string) config('preview.storage_path', ''),
            'fixture_path' => (string) config('preview.fixture_path', ''),
            'test_path' => (string) config('preview.test_path', ''),
            'scenario_path' => (string) config('preview.scenario_path', ''),
            'http_capture' => [
                'enabled' => (bool) config('preview.http_capture.enabled', true),
                'path' => (string) config('preview.http_capture.path', ''),
            ],
            'route_preview' => [
                'enabled' => (bool) config('preview.route_preview.enabled', true),
                'path' => (string) config('preview.route_preview.path', ''),
            ],
            'live_enabled' => (bool) config('preview.live_enabled', false),
            'transports' => $this->configuredClassMap('preview.transports'),
            'providers' => $this->configuredClassMap('preview.providers'),
            'redacted_headers' => $this->stringList((array) config('preview.redact_headers', [])),
            'provider_secret_status' => $this->providerSecretStatus(),
            'transport_binary_status' => $this->transportBinaryStatus(),
        ];
    }

    /** @return array<string, string> */
    private function configuredClassMap(string $configKey): array
    {
        $configured = (array) config($configKey, []);
        $rows = [];

        foreach ($configured as $name => $class) {
            if (! is_string($name) || ! is_string($class) || trim($name) === '' || trim($class) === '') {
                continue;
            }

            $rows[$name] = $class;
        }

        return $rows;
    }

    /** @return list<string> */
    private function stringList(array $values): array
    {
        $strings = [];

        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                $strings[] = $value;
            }
        }

        return $strings;
    }

    /** @return array<string, array{status: string, config_key: string}> */
    private function providerSecretStatus(): array
    {
        $statuses = [];

        foreach (self::PROVIDER_SECRET_CONFIG as $name => $secretConfig) {
            $secret = config($secretConfig['config_key']);

            $statuses[$name] = [
                'status' => $this->secretStatus($secret, $secretConfig['placeholder']),
                'config_key' => $secretConfig['config_key'],
            ];
        }

        return $statuses;
    }

    private function secretStatus(mixed $secret, string $placeholder): string
    {
        if (! is_string($secret) || trim($secret) === '') {
            return 'missing';
        }

        return hash_equals($placeholder, $secret) ? 'placeholder' : 'configured';
    }

    /** @return array<string, array{binary: string, configured: bool}> */
    private function transportBinaryStatus(): array
    {
        $statuses = [];

        foreach (self::TRANSPORT_BINARY_CONFIG as $name => $configKey) {
            $binary = config($configKey);
            $binary = is_string($binary) ? $binary : '';

            $statuses[$name] = [
                'binary' => $binary,
                'configured' => trim($binary) !== '',
            ];
        }

        return $statuses;
    }

    /** @param list<string> $values */
    private function listValue(array $values): string
    {
        return $values === [] ? 'none' : implode(', ', $values);
    }
}

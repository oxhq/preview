<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use Oxhq\Preview\Core\ProviderRegistry;
use Oxhq\Preview\Providers\GenericHmacProvider;
use Oxhq\Preview\Providers\GenericProvider;
use Oxhq\Preview\Providers\GitHubProvider;
use Oxhq\Preview\Providers\PreviewProvider;
use Oxhq\Preview\Providers\ProviderCapability;
use Oxhq\Preview\Providers\ShopifyProvider;
use Oxhq\Preview\Providers\StripeProvider;

final class ProviderDoctorCommand extends Command
{
    protected $signature = 'preview:provider:doctor {--json : Output provider diagnostics as JSON}';

    protected $description = 'Diagnose Laravel Preview provider capabilities and configuration without printing secrets.';

    /** @var list<class-string<PreviewProvider>> */
    private const BUILT_IN_PROVIDERS = [
        GenericProvider::class,
        GenericHmacProvider::class,
        GitHubProvider::class,
        ShopifyProvider::class,
        StripeProvider::class,
    ];

    /** @var array<class-string<PreviewProvider>, array{config_key: string, placeholder: string, label: string}> */
    private const SECRET_CONFIG = [
        GenericHmacProvider::class => [
            'config_key' => 'preview.hmac.secret',
            'placeholder' => 'preview-secret',
            'label' => 'HMAC shared secret',
        ],
        GitHubProvider::class => [
            'config_key' => 'preview.github.webhook_secret',
            'placeholder' => 'github-preview-secret',
            'label' => 'GitHub webhook secret',
        ],
        ShopifyProvider::class => [
            'config_key' => 'preview.shopify.client_secret',
            'placeholder' => 'shopify-preview-secret',
            'label' => 'Shopify client secret',
        ],
        StripeProvider::class => [
            'config_key' => 'preview.stripe.endpoint_secret',
            'placeholder' => 'whsec_preview',
            'label' => 'Stripe endpoint secret',
        ],
    ];

    public function __construct(private readonly ProviderRegistry $providers)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $rows = $this->diagnostics();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->line('No preview providers registered.');

            return self::SUCCESS;
        }

        $this->line('Preview provider diagnostics:');

        foreach ($rows as $row) {
            $this->line(sprintf(' - %s [%s]: %s', $row['name'], $row['source'], $row['class']));
            $this->line('   Capabilities: '.implode(', ', $row['capabilities']));
            $this->line('   Can sign: '.($row['can_sign'] ? 'yes' : 'no'));
            $this->line(sprintf(
                '   Configuration: %s (%s)',
                $row['configuration_status'],
                $row['configuration_message'],
            ));
            $this->line('   Ready: '.($row['ready'] ? 'yes' : 'no'));
        }

        return self::SUCCESS;
    }

    /**
     * @return list<array{name: string, source: string, class: class-string<PreviewProvider>, capabilities: list<string>, can_sign: bool, configuration_status: string, configuration_message: string, config_key: ?string, ready: bool}>
     */
    private function diagnostics(): array
    {
        return array_map(
            fn (PreviewProvider $provider): array => [
                'name' => $provider->name(),
                'source' => $this->sourceFor($provider),
                'class' => $provider::class,
                'capabilities' => array_map(
                    fn (ProviderCapability $capability): string => $capability->name,
                    $provider->capabilities(),
                ),
                'can_sign' => $provider->canSign(),
                ...$this->configurationFor($provider),
            ],
            array_values($this->providers->all()),
        );
    }

    /**
     * @return array{configuration_status: string, configuration_message: string, config_key: ?string, ready: bool}
     */
    private function configurationFor(PreviewProvider $provider): array
    {
        $providerClass = $provider::class;

        if ($providerClass === GenericProvider::class) {
            return [
                'configuration_status' => 'ready',
                'configuration_message' => 'no secret required',
                'config_key' => null,
                'ready' => true,
            ];
        }

        if (! isset(self::SECRET_CONFIG[$providerClass])) {
            return [
                'configuration_status' => 'unknown',
                'configuration_message' => 'custom provider configuration is not inspectable',
                'config_key' => null,
                'ready' => true,
            ];
        }

        $secretConfig = self::SECRET_CONFIG[$providerClass];
        $secret = config($secretConfig['config_key']);

        if (! is_string($secret) || trim($secret) === '') {
            return [
                'configuration_status' => 'fail',
                'configuration_message' => $secretConfig['label'].' is not configured',
                'config_key' => $secretConfig['config_key'],
                'ready' => false,
            ];
        }

        if (hash_equals($secretConfig['placeholder'], $secret)) {
            return [
                'configuration_status' => 'warning',
                'configuration_message' => $secretConfig['label'].' is using the package placeholder/default secret',
                'config_key' => $secretConfig['config_key'],
                'ready' => false,
            ];
        }

        return [
            'configuration_status' => 'ready',
            'configuration_message' => $secretConfig['label'].' is configured',
            'config_key' => $secretConfig['config_key'],
            'ready' => true,
        ];
    }

    private function sourceFor(PreviewProvider $provider): string
    {
        return in_array($provider::class, self::BUILT_IN_PROVIDERS, true) ? 'built-in' : 'custom';
    }
}

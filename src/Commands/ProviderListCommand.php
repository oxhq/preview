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

final class ProviderListCommand extends Command
{
    protected $signature = 'preview:provider:list {--json : Output provider diagnostics as JSON}';

    protected $description = 'List registered Laravel Preview providers and capabilities.';

    /** @var list<class-string<PreviewProvider>> */
    private const BUILT_IN_PROVIDERS = [
        GenericProvider::class,
        GenericHmacProvider::class,
        GitHubProvider::class,
        ShopifyProvider::class,
        StripeProvider::class,
    ];

    public function __construct(private readonly ProviderRegistry $providers)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $providers = $this->providerRows();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($providers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($providers === []) {
            $this->line('No preview providers registered.');

            return self::SUCCESS;
        }

        $this->line('Preview providers:');

        foreach ($providers as $provider) {
            $this->line(sprintf(
                ' - %s [%s] %s: %s',
                $provider['name'],
                $provider['source'],
                $provider['class'],
                implode(', ', $provider['capabilities']),
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @return list<array{name: string, source: string, class: class-string<PreviewProvider>, capabilities: list<string>}>
     */
    private function providerRows(): array
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
            ],
            array_values($this->providers->all()),
        );
    }

    private function sourceFor(PreviewProvider $provider): string
    {
        return in_array($provider::class, self::BUILT_IN_PROVIDERS, true) ? 'built-in' : 'custom';
    }
}

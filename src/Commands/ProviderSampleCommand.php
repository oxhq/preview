<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Oxhq\Preview\Capture\PreviewRequest;
use Oxhq\Preview\Core\ProviderRegistry;
use Oxhq\Preview\Providers\PreviewProvider;

final class ProviderSampleCommand extends Command
{
    protected $signature = 'preview:provider:sample
        {provider : Built-in provider name: generic, hmac, github, shopify, stripe}
        {--event= : Synthetic event type to place in the sample}
        {--json : Output the sample as JSON}';

    protected $description = 'Generate a safe synthetic request sample for a built-in Laravel Preview provider.';

    /** @var list<string> */
    private const BUILT_IN_PROVIDERS = [
        'generic',
        'hmac',
        'github',
        'shopify',
        'stripe',
    ];

    /** @var array<string, string> */
    private const DEFAULT_EVENTS = [
        'generic' => 'preview.sample',
        'hmac' => 'preview.sample',
        'github' => 'push',
        'shopify' => 'orders/create',
        'stripe' => 'checkout.session.completed',
    ];

    public function __construct(private readonly ProviderRegistry $providers)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $providerName = strtolower(trim((string) $this->argument('provider')));

        if (! in_array($providerName, self::BUILT_IN_PROVIDERS, true)) {
            $this->line('Provider samples are only available for built-in providers: '.implode(', ', self::BUILT_IN_PROVIDERS).'.');

            return self::FAILURE;
        }

        try {
            $provider = $this->providers->get($providerName);
        } catch (InvalidArgumentException $exception) {
            $this->line($exception->getMessage());

            return self::FAILURE;
        }

        $sample = $this->sample($providerName, $provider);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->line('Preview provider sample: '.$sample['provider']);
        $this->line('Event: '.$sample['event']);
        $this->line('Method: '.$sample['method']);
        $this->line('Path: '.$sample['path']);
        $this->line('Signed: '.($sample['signed'] ? 'yes' : 'no'));
        $this->line('Fixture name: '.$sample['fixture_name']);
        $this->line('Headers:');

        foreach ($sample['headers'] as $name => $value) {
            $this->line(sprintf(' - %s: %s', $name, $value));
        }

        $this->line('Body:');
        $this->line($sample['raw_body']);

        return self::SUCCESS;
    }

    /**
     * @return array{provider: string, event: string, method: string, path: string, headers: array<string, string>, raw_body: string, signed: bool, fixture_name: string, notes: list<string>}
     */
    private function sample(string $providerName, PreviewProvider $provider): array
    {
        $event = $this->event($providerName);
        [$headers, $rawBody] = $this->baseRequest($providerName, $event);
        $signed = $provider->canSign();

        if ($signed) {
            $headers = $provider->sign($rawBody, $headers);
        }

        $request = PreviewRequest::make(
            provider: $providerName,
            method: 'POST',
            path: $this->path($providerName),
            headers: $headers,
            rawBody: $rawBody,
        );

        return [
            'provider' => $providerName,
            'event' => $event,
            'method' => 'POST',
            'path' => $this->path($providerName),
            'headers' => $headers,
            'raw_body' => $rawBody,
            'signed' => $signed,
            'fixture_name' => $provider->fixtureName($request),
            'notes' => [
                'Synthetic sample data only.',
                'Configured provider secrets are not printed.',
            ],
        ];
    }

    /**
     * @return array{0: array<string, string>, 1: string}
     */
    private function baseRequest(string $providerName, string $event): array
    {
        return match ($providerName) {
            'generic', 'hmac' => [
                [
                    'Content-Type' => 'application/json',
                    'X-Preview-Event' => $event,
                ],
                $this->jsonBody([
                    'id' => 'evt_preview_sample',
                    'event' => $event,
                    'data' => [
                        'object' => [
                            'id' => 'obj_preview_sample',
                        ],
                    ],
                ]),
            ],
            'github' => [
                [
                    'Content-Type' => 'application/json',
                    'X-GitHub-Event' => $event,
                    'X-GitHub-Delivery' => 'preview-sample-delivery',
                ],
                $this->jsonBody([
                    'action' => $event === 'pull_request' ? 'opened' : 'created',
                    'repository' => [
                        'full_name' => 'preview/sample',
                    ],
                    'sender' => [
                        'login' => 'preview-bot',
                    ],
                ]),
            ],
            'shopify' => [
                [
                    'Content-Type' => 'application/json',
                    'X-Shopify-Topic' => $event,
                    'X-Shopify-Shop-Domain' => 'preview.myshopify.com',
                    'X-Shopify-Webhook-Id' => 'preview-sample-webhook',
                ],
                $this->jsonBody([
                    'id' => 1000000001,
                    'topic' => $event,
                    'shop_domain' => 'preview.myshopify.com',
                    'admin_graphql_api_id' => 'gid://shopify/WebhookSubscription/1',
                ]),
            ],
            'stripe' => [
                [
                    'Content-Type' => 'application/json',
                ],
                $this->jsonBody([
                    'id' => 'evt_preview_sample',
                    'object' => 'event',
                    'api_version' => '2024-06-20',
                    'type' => $event,
                    'data' => [
                        'object' => [
                            'id' => 'cs_preview_sample',
                            'object' => 'checkout.session',
                        ],
                    ],
                ]),
            ],
        };
    }

    private function event(string $providerName): string
    {
        $event = $this->option('event');

        if (is_string($event) && trim($event) !== '') {
            return trim($event);
        }

        return self::DEFAULT_EVENTS[$providerName];
    }

    private function path(string $providerName): string
    {
        return '/__preview/capture/'.$providerName;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonBody(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}

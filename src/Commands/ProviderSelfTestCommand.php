<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Oxhq\Preview\Capture\PreviewRequest;
use Oxhq\Preview\Core\ProviderRegistry;
use Oxhq\Preview\Providers\PreviewProvider;

final class ProviderSelfTestCommand extends Command
{
    protected $signature = 'preview:provider:self-test
        {provider? : Built-in provider name: generic, hmac, github, shopify, stripe}
        {--json : Output self-test results as JSON}';

    protected $description = 'Run synthetic in-memory verification checks for built-in Laravel Preview providers without printing secrets.';

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
        $providerNames = $this->providerNames();

        if ($providerNames === []) {
            $this->line('Provider self-tests are only available for built-in providers: '.implode(', ', self::BUILT_IN_PROVIDERS).'.');

            return self::FAILURE;
        }

        try {
            $rows = array_map(
                fn (string $providerName): array => $this->selfTest($providerName, $this->providers->get($providerName)),
                $providerNames,
            );
        } catch (InvalidArgumentException $exception) {
            $this->line($exception->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $this->exitCode($rows);
        }

        $this->line('Preview provider self-test:');

        foreach ($rows as $row) {
            $this->line(sprintf(
                ' - %s: %s',
                $row['provider'],
                $row['status'],
            ));
            $this->line('   Event: '.$row['event']);

            if ($row['message'] !== null) {
                $this->line('   Message: '.$row['message']);
            }
        }

        return $this->exitCode($rows);
    }

    /**
     * @return list<string>
     */
    private function providerNames(): array
    {
        $provider = $this->argument('provider');

        if (! is_string($provider) || trim($provider) === '') {
            return self::BUILT_IN_PROVIDERS;
        }

        $provider = strtolower(trim($provider));

        return in_array($provider, self::BUILT_IN_PROVIDERS, true) ? [$provider] : [];
    }

    /**
     * @return array{provider: string, event: string, verified: bool, status: string, ok: bool, message: ?string}
     */
    private function selfTest(string $providerName, PreviewProvider $provider): array
    {
        $event = self::DEFAULT_EVENTS[$providerName];
        [$headers, $rawBody] = $this->baseRequest($providerName, $event);

        if ($provider->canSign()) {
            $headers = $provider->sign($rawBody, $headers);
        }

        $request = PreviewRequest::make(
            provider: $providerName,
            method: 'POST',
            path: '/__preview/capture/'.$providerName,
            headers: $headers,
            rawBody: $rawBody,
        );

        $verification = $provider->verify($request);
        $status = $this->status($provider, $verification->verified, $verification->message);

        return [
            'provider' => $providerName,
            'event' => $provider->eventType($request) ?? $event,
            'verified' => $verification->verified,
            'status' => $status,
            'ok' => $status !== 'failed',
            'message' => $this->safeVerificationMessage($verification->message, $request),
        ];
    }

    private function status(PreviewProvider $provider, bool $verified, ?string $message): string
    {
        if ($verified) {
            return 'verified';
        }

        if (! $provider->canSign() && is_string($message) && str_contains(strtolower($message), 'does not verify')) {
            return 'skipped';
        }

        return 'failed';
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
                    'id' => 'evt_preview_self_test',
                    'event' => $event,
                    'data' => [
                        'object' => [
                            'id' => 'obj_preview_self_test',
                        ],
                    ],
                ]),
            ],
            'github' => [
                [
                    'Content-Type' => 'application/json',
                    'X-GitHub-Event' => $event,
                    'X-GitHub-Delivery' => 'preview-self-test-delivery',
                ],
                $this->jsonBody([
                    'action' => 'created',
                    'repository' => [
                        'full_name' => 'preview/self-test',
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
                    'X-Shopify-Webhook-Id' => 'preview-self-test-webhook',
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
                    'id' => 'evt_preview_self_test',
                    'object' => 'event',
                    'api_version' => '2024-06-20',
                    'type' => $event,
                    'data' => [
                        'object' => [
                            'id' => 'cs_preview_self_test',
                            'object' => 'checkout.session',
                        ],
                    ],
                ]),
            ],
        };
    }

    private function safeVerificationMessage(?string $message, PreviewRequest $request): ?string
    {
        if ($message === null) {
            return null;
        }

        foreach ($this->sensitiveValues($request) as $value) {
            $message = str_replace($value, '[redacted]', $message);
        }

        return $message;
    }

    /**
     * @return list<string>
     */
    private function sensitiveValues(PreviewRequest $request): array
    {
        $values = [$request->rawBody];

        array_push($values, ...$this->scalarValues($request->headers));
        $values = array_values(array_unique(array_filter(
            array_map(fn (mixed $value): string => (string) $value, $values),
            fn (string $value): bool => $value !== '',
        )));

        usort($values, fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        return $values;
    }

    /**
     * @param array<mixed> $values
     * @return list<string>
     */
    private function scalarValues(array $values): array
    {
        $scalars = [];

        foreach ($values as $value) {
            if (is_array($value)) {
                array_push($scalars, ...$this->scalarValues($value));

                continue;
            }

            if (is_scalar($value)) {
                $scalars[] = (string) $value;
            }
        }

        return $scalars;
    }

    /**
     * @param list<array{provider: string, event: string, verified: bool, status: string, ok: bool, message: ?string}> $rows
     */
    private function exitCode(array $rows): int
    {
        foreach ($rows as $row) {
            if (! $row['ok']) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonBody(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}

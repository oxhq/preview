<?php

declare(strict_types=1);

use Oxhq\Preview\Providers\GenericHmacProvider;
use Oxhq\Preview\Providers\GenericProvider;
use Oxhq\Preview\Providers\GitHubProvider;
use Oxhq\Preview\Providers\ShopifyProvider;
use Oxhq\Preview\Providers\StripeProvider;
use Oxhq\Preview\Core\Transport\CloudflareTunnelTransport;
use Oxhq\Preview\Core\Transport\NgrokTunnelTransport;
use Oxhq\Preview\Core\Transport\StripeCliTunnelTransport;

return [
    'storage_path' => env('PREVIEW_STORAGE_PATH', storage_path('framework/preview/captures')),
    'fixture_path' => env('PREVIEW_FIXTURE_PATH', base_path('tests/Fixtures/Preview')),
    'test_path' => env('PREVIEW_TEST_PATH', base_path('tests/Feature')),

    'redact_headers' => [
        'authorization',
        'cookie',
        'set-cookie',
        'x-hub-signature-256',
        'x-shopify-hmac-sha256',
        'x-stripe-signature',
        'stripe-signature',
    ],

    'providers' => [
        'generic' => GenericProvider::class,
        'hmac' => GenericHmacProvider::class,
        'github' => GitHubProvider::class,
        'shopify' => ShopifyProvider::class,
        'stripe' => StripeProvider::class,
    ],

    'transports' => [
        'cloudflare' => CloudflareTunnelTransport::class,
        'ngrok' => NgrokTunnelTransport::class,
        'stripe-cli' => StripeCliTunnelTransport::class,
    ],

    'transport_binaries' => [
        'cloudflare' => env('PREVIEW_CLOUDFLARED_BINARY', 'cloudflared'),
        'ngrok' => env('PREVIEW_NGROK_BINARY', 'ngrok'),
        'stripe_cli' => env('PREVIEW_STRIPE_CLI_BINARY', 'stripe'),
    ],

    'transport_readiness_delay' => [
        'cloudflare' => (float) env('PREVIEW_CLOUDFLARE_READINESS_DELAY', 6),
    ],

    'live_enabled' => env('PREVIEW_LIVE_ENABLED', false),

    'http_capture' => [
        'enabled' => env('PREVIEW_HTTP_CAPTURE_ENABLED', true),
        'path' => env('PREVIEW_HTTP_CAPTURE_PATH', '/__preview/capture/{provider}'),
    ],

    'route_preview' => [
        'enabled' => env('PREVIEW_ROUTE_PREVIEW_ENABLED', true),
        'path' => env('PREVIEW_ROUTE_PREVIEW_PATH', '/__preview/route/{route}'),
        'default_ttl' => env('PREVIEW_ROUTE_PREVIEW_TTL', '2h'),
        'user_model' => env('PREVIEW_ROUTE_PREVIEW_USER_MODEL'),
    ],

    'scenario_path' => env('PREVIEW_SCENARIO_PATH', base_path('preview/scenarios')),

    'hmac' => [
        'signature_header' => env('PREVIEW_HMAC_SIGNATURE_HEADER', 'X-Signature'),
        'secret' => env('PREVIEW_HMAC_SECRET', 'preview-secret'),
        'algorithm' => env('PREVIEW_HMAC_ALGORITHM', 'sha256'),
    ],

    'github' => [
        'webhook_secret' => env('PREVIEW_GITHUB_WEBHOOK_SECRET', 'github-preview-secret'),
    ],

    'shopify' => [
        'client_secret' => env('PREVIEW_SHOPIFY_CLIENT_SECRET', 'shopify-preview-secret'),
    ],

    'stripe' => [
        'endpoint_secret' => env('PREVIEW_STRIPE_ENDPOINT_SECRET', 'whsec_preview'),
        'tolerance' => (int) env('PREVIEW_STRIPE_TOLERANCE', 300),
    ],
];

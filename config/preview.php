<?php

declare(strict_types=1);

use Oxhq\Preview\Providers\GenericHmacProvider;
use Oxhq\Preview\Providers\GenericProvider;
use Oxhq\Preview\Providers\StripeProvider;
use Oxhq\Preview\Core\Transport\CloudflareTunnelTransport;
use Oxhq\Preview\Core\Transport\NgrokTunnelTransport;

return [
    'storage_path' => env('PREVIEW_STORAGE_PATH', storage_path('framework/preview/captures')),
    'fixture_path' => env('PREVIEW_FIXTURE_PATH', base_path('tests/Fixtures/Preview')),
    'test_path' => env('PREVIEW_TEST_PATH', base_path('tests/Feature/Preview')),

    'redact_headers' => [
        'authorization',
        'cookie',
        'set-cookie',
        'x-stripe-signature',
        'stripe-signature',
    ],

    'providers' => [
        'generic' => GenericProvider::class,
        'hmac' => GenericHmacProvider::class,
        'stripe' => StripeProvider::class,
    ],

    'transports' => [
        'cloudflare' => CloudflareTunnelTransport::class,
        'ngrok' => NgrokTunnelTransport::class,
        'stripe-cli' => null,
    ],

    'live_enabled' => env('PREVIEW_LIVE_ENABLED', false),

    'hmac' => [
        'signature_header' => env('PREVIEW_HMAC_SIGNATURE_HEADER', 'X-Signature'),
        'secret' => env('PREVIEW_HMAC_SECRET', 'preview-secret'),
        'algorithm' => env('PREVIEW_HMAC_ALGORITHM', 'sha256'),
    ],

    'stripe' => [
        'endpoint_secret' => env('PREVIEW_STRIPE_ENDPOINT_SECRET', 'whsec_preview'),
        'tolerance' => (int) env('PREVIEW_STRIPE_TOLERANCE', 300),
    ],
];

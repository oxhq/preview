# Laravel Preview

Laravel Preview is a local-first Laravel package for turning real inbound application traffic into replayable captures, fixtures, and generated Pest-style tests.

It is not a tunnel product or a hosted request bin. Tunnels get traffic to your machine; Laravel Preview starts once that traffic reaches Laravel and makes the flow reproducible.

## What It Does

- Captures inbound requests through `/__preview/capture/{provider}`.
- Preserves raw request bodies and raw headers for exact replay.
- Stores redacted capture metadata locally by default.
- Verifies and re-signs provider traffic through provider adapters.
- Generates fixture files and Pest-compatible test files from captures.
- Supports safe route preview for signed, time-limited route links with explicit safety controls.

## Supported Providers

| Provider | Signature verification | Re-sign replay | Event extraction |
| --- | --- | --- | --- |
| Generic | No | No | `X-Preview-Event` |
| Generic HMAC | Yes | Yes | `X-Preview-Event` |
| Stripe | Yes | Yes | payload `type` |
| GitHub | Yes | Yes | `X-GitHub-Event` |
| Shopify | Yes | Yes | `X-Shopify-Topic` |

Candidate providers in `docs/preview/provider-capability-matrix.md` are not supported until they have implementation and tests.

## Installation

```bash
composer require --dev oxhq/preview
```

Publish the config when you need to change storage, provider secrets, transport binaries, or capture-route settings:

```bash
php artisan vendor:publish --tag=preview-config
```

For local development before the package is published, require it through a Composer path repository from a Laravel app:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../preview",
      "options": {
        "symlink": true
      }
    }
  ],
  "require-dev": {
    "oxhq/preview": "*"
  }
}
```

## Capture

Synthetic local capture:

```bash
php artisan preview:capture generic \
  --path=/webhooks/orders \
  --header="X-Preview-Event: order.created" \
  --body='{"id":1}'
```

Live tunnel capture requires both config and CLI opt-in:

```bash
PREVIEW_LIVE_ENABLED=true php artisan preview:capture generic \
  --transport=cloudflare \
  --local-url=http://127.0.0.1:8000 \
  --live \
  --hold-seconds=60
```

Capture storage is local-first. Raw captures and local-only fixture payloads are gitignored by default.

## Replay And Generate

```bash
php artisan preview:capture:list
php artisan preview:capture:show {capture}
php artisan preview:capture:replay {capture} --exact
php artisan preview:capture:replay {capture} --resign
php artisan preview:capture:fixture {capture}
php artisan preview:capture:test {capture}
```

`--exact` replays captured raw headers and raw body. `--resign` asks the provider adapter to generate fresh valid signature headers when supported.

## Route Preview

```bash
php artisan preview:route billing.portal \
  --ttl=2h \
  --param=id=123 \
  --session=currency=usd \
  --readonly-db \
  --guard=client \
  --fake-queue \
  --fake-mail
```

Route preview creates signed, time-limited links for named Laravel routes and proxies execution through the signed preview endpoint. Explicit opt-in is required before non-read methods are exposed.

`--readonly-db` wraps the covered request in a database transaction so database writes can be rolled back. It is not a complete read-only guarantee: queues, mail, cache, filesystem writes, external HTTP, and events are outside that database wrapper unless explicit fake flags are used for the side effects the package supports.

Repeated `--session=key=value` flags carry session context into the proxied preview request. `--user-id` plus optional `--user-model` can attach an app-specific authenticated user context for the proxied request. `--guard` selects the guard for that explicit user context and remains audit metadata; by itself it does not authenticate a user, isolate the filesystem, or isolate cache.

Route preview now behavior-tests the supported queue, mail, HTTP, and event fakes. The auth context is not a generic authorization bypass, policy bypass, or complete isolation boundary.

## Scenario Workbench

The first v1.0 Scenario foundation slice keeps scenarios local to the Laravel app. A scenario is a PHP file under `preview/scenarios` by default, or the path configured by `PREVIEW_SCENARIO_PATH`, that returns an `Oxhq\Preview\Scenario\Scenario` instance.

```php
use App\Database\Seeders\DemoSubscriptionSeeder;
use Oxhq\Preview\Scenario\Scenario;

return new Scenario(
    name: 'subscription-renewal',
    seed: DemoSubscriptionSeeder::class,
    routes: ['billing.portal'],
    routeParameters: [
        'billing.portal' => ['id' => '123'],
    ],
    captures: ['20260505011852323-sugxujb2'],
    fakes: ['queue', 'mail'],
    notes: 'Exercises the local renewal review flow after a captured provider callback.',
);
```

Scenario files record capture IDs, route names, optional route parameters, fake boundaries, an optional seed class, and optional notes. The discovery commands are intentionally read-only:

```bash
php artisan preview:scenario:list
php artisan preview:scenario:show subscription-renewal
```

Scenario replay now runs the configured seeder before replay through Laravel's normal seeder path and composes each listed capture through the existing replay engine:

```bash
php artisan preview:scenario:replay subscription-renewal --exact
php artisan preview:scenario:replay subscription-renewal --resign
```

`--exact` replays each scenario capture with the stored raw body and captured headers. `--resign` replays each scenario capture with the stored raw body plus fresh provider-valid signature headers when the provider supports signing. Scenario replay fails clearly when a capture cannot be found, a provider cannot re-sign, or a configured seeder fails.

Scenario route composition is package-local behavior only when named route previews execute through `preview:scenario:replay` under the same route-preview safety limits. Scenario fakes such as `queue`, `mail`, `http`, and `events` are fake requests to the route-preview layer; they do not create complete side-effect isolation for cache, filesystem, external services outside Laravel's fake boundary, authorization policy bypass, or hosted sharing.

Scenario Pest test generation now writes Pest-compatible scenario test files from local scenario files. Package tests prove the generated file content and command behavior; they do not prove a fresh consumer app running those Pest files. Captures, route preview, fixtures, generated capture-level Pest tests, and the package-internal Scenario replay slices are the proven local surfaces today.

## Current Proof Boundary

This repository has package-internal Testbench coverage for capture, replay, fixture generation, provider contracts, route preview, and the implemented Scenario slices. It also has a recorded Laravel 12 path-repository consumer smoke for package discovery and synthetic capture. Package tests may prove local package behavior; they do not prove Packagist publication, hosted CI, SaaS behavior, live tunnel startup with real cloudflared/ngrok binaries, or a fresh consumer app running every Scenario workflow.

Run local verification:

```bash
composer validate --strict
composer test
```

## Documentation

- `docs/preview/spec.md` defines the product boundary and long-term architecture.
- `docs/preview/plans/v0.1-capture-to-test.md` tracks the capture-to-test implementation plan.
- `docs/preview/provider-capability-matrix.md` lists supported and candidate provider status.
- `docs/preview/provider-contribution-guide.md` explains how to add a provider without special-casing core services.

## License

MIT

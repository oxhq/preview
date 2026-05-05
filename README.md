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

Route preview now behavior-tests the supported queue, mail, HTTP, and event fakes. The auth context is not a generic authorization bypass, policy bypass, or full scenario-isolation feature.

## Current Proof Boundary

This repository has package-internal Testbench coverage for capture, replay, fixture generation, provider contracts, and route preview. It also has a recorded Laravel 12 path-repository consumer smoke. That is still not proof of Packagist publication, hosted CI, or live tunnel startup with real cloudflared/ngrok binaries.

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

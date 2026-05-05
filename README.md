# Laravel Preview

Laravel Preview is a local-first Laravel package for reproducing real application flows.
It captures inbound traffic, verifies provider context, replays requests, writes fixtures,
generates Pest-compatible tests, previews named routes safely, and composes those pieces
into reusable local scenarios.

It is not a tunnel product, a hosted request bin, or a Stripe-only webhook debugger.
Tunnels get traffic to your machine. Laravel Preview starts once traffic reaches Laravel
and turns that flow into local development assets.

## Install

```bash
composer require --dev oxhq/preview
php artisan vendor:publish --tag=preview-config
```

Before the package is published, install it from a Laravel app through a Composer path repository:

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

## What Ships

- Local HTTP capture through `/__preview/capture/{provider}`.
- Synthetic capture through Artisan for repeatable local checks.
- Exact replay with captured raw body and headers.
- Provider-aware re-sign replay for providers that support signing.
- Fixture generation and Pest-compatible capture tests.
- Signed route preview for named Laravel routes.
- Local scenario files that compose seeds, captures, route previews, fakes, and notes.
- Scenario replay and Pest-compatible scenario test generation.

## Providers

| Provider | Verifies signatures | Re-signs replay | Event source |
| --- | --- | --- | --- |
| Generic | No | No | `X-Preview-Event` |
| Generic HMAC | Yes | Yes | `X-Preview-Event` |
| Stripe | Yes | Yes | payload `type` |
| GitHub | Yes | Yes | `X-GitHub-Event` |
| Shopify | Yes | Yes | `X-Shopify-Topic` |

Stripe is a high-fidelity reference provider, not the product center. Provider-specific
logic belongs inside provider adapters, not in the capture, replay, fixture, or test
generation services.

## Capture

Synthetic local capture:

```bash
php artisan preview:capture generic \
  --path=/webhooks/orders \
  --header="X-Preview-Event: order.created" \
  --body='{"id":1}'
```

Live tunnel capture requires explicit config and CLI opt-in:

```bash
PREVIEW_LIVE_ENABLED=true php artisan preview:capture generic \
  --transport=cloudflare \
  --local-url=http://127.0.0.1:8000 \
  --live \
  --hold-seconds=60
```

Capture commands:

```bash
php artisan preview:capture generic
php artisan preview:capture hmac --signature-header=X-Signature
php artisan preview:capture stripe
php artisan preview:capture stripe --transport=stripe-cli --live --local-url=http://127.0.0.1:8000
php artisan preview:capture:list
php artisan preview:capture:show {capture}
php artisan preview:capture:replay {capture} --exact
php artisan preview:capture:replay {capture} --resign
php artisan preview:capture:fixture {capture}
php artisan preview:capture:test {capture}
```

Raw captures stay local. Metadata and generated fixtures redact configured sensitive
headers such as cookies and authorization values.

`stripe-cli` is an optional convenience transport. It starts `stripe listen` and forwards
Stripe events to the local Preview Stripe capture endpoint. It still requires `--live`,
`preview.live_enabled=true`, a runnable Stripe CLI binary, and normal Stripe CLI auth.

## Route Preview

Route preview creates signed, time-limited links for named Laravel routes and proxies
execution through Laravel Preview's signed route endpoint.

```bash
php artisan preview:route billing.portal \
  --ttl=2h \
  --param=id=123 \
  --session=currency=usd \
  --readonly-db \
  --guard=web \
  --user-id=42 \
  --user-model="App\Models\User" \
  --fake-queue \
  --fake-mail \
  --fake-http \
  --fake-events
```

Safety boundaries are explicit:

- `--readonly-db` wraps the covered preview request in a database transaction. It is not full read-only mode.
- `--guard` selects or records guard context. It does not authenticate a user by itself.
- `--user-id` plus optional `--user-model` resolves an app-specific authenticatable user for the proxied request.
- fake flags cover the supported Laravel queue, mail, HTTP, and event facades only.
- route preview does not isolate cache, filesystem writes, arbitrary external services, policies, or authorization logic.
- non-read routes are blocked unless the command explicitly opts into write-method preview.

## Scenarios

A scenario is a local PHP file under `preview/scenarios` by default, or the configured
`preview.scenario_path`, that returns `Oxhq\Preview\Scenario\Scenario`.

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
    routeContext: [
        'billing.portal' => [
            'session' => ['tenant' => 'acme'],
            'guard' => 'web',
            'user_id' => '42',
            'user_model' => App\Models\User::class,
            'readonly_db' => true,
            'fakes' => ['mail'],
        ],
    ],
    captures: ['20260505011852323-sugxujb2'],
    fakes: ['queue', 'events'],
    notes: 'Exercises renewal review after a provider callback.',
);
```

Scenario commands:

```bash
php artisan preview:scenario:make subscription-renewal \
  --seed="App\Database\Seeders\DemoSubscriptionSeeder" \
  --capture=20260505011852323-sugxujb2 \
  --route=billing.portal \
  --param=billing.portal:id=123 \
  --route-session=billing.portal:tenant=acme \
  --route-guard=billing.portal=web \
  --route-user="billing.portal:42:App\Models\User" \
  --route-readonly-db=billing.portal \
  --route-fake=billing.portal:mail

php artisan preview:scenario:list
php artisan preview:scenario:show subscription-renewal
php artisan preview:scenario:replay subscription-renewal --exact
php artisan preview:scenario:replay subscription-renewal --resign
php artisan preview:scenario:test subscription-renewal
```

Scenario replay runs the configured seeder through Laravel's normal seeder path, replays
listed captures, dispatches captures when `--send-to` is provided, and executes listed
routes through the same signed route-preview safety layer. Replay prints a summary of
seed, capture, dispatch, and route counts, and failures include the failing dispatch or
route when a partial result exists. Scenario fakes are forwarded to route preview; they do
not provide broader isolation than the route-preview fake flags.

Generated scenario tests are Pest-compatible and local-first. They are meant to fail
clearly when the host app lacks required routes, models, provider secrets, seed data, or
database state. Generated scenario tests call `ScenarioRunner` directly and assert the
replay result object instead of only checking command text.

## Proof Boundary

Current proof in this repository is package-local Testbench proof plus a recorded Laravel
12 Composer path-repository smoke for package discovery, synthetic generic capture,
scenario creation, scenario replay, generated scenario test creation, and generated Pest
execution in that consumer app.
The package test suite covers capture, replay, fixture generation, provider contracts,
route preview, scenario replay, route composition, fake propagation, and generated test
syntax/structure.

This does not prove:

- Packagist installation.
- hosted CI.
- SaaS, managed relay, persistent URLs, team sharing, or audit logs.
- ngrok live startup.
- full public tunnel ingress from this machine. A real `cloudflared` binary starts and
  prints a public URL locally, but public DNS resolution for the generated
  `trycloudflare.com` hostname was blocked during local proof.
- real production provider traffic.
- every generated scenario test shape in every consumer app.

Run local verification:

```bash
composer validate --strict
composer test
```

## License

MIT

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

Local readiness summary:

```bash
php artisan preview:doctor
php artisan preview:doctor --json
php artisan preview:config
php artisan preview:config --json
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

Inspect registered providers without starting any live traffic:

```bash
php artisan preview:provider:list
php artisan preview:provider:list --json
php artisan preview:provider:doctor
php artisan preview:provider:doctor --json
```

`preview:provider:doctor` reports provider capabilities and whether built-in provider
secrets still use placeholder values. It does not print secret values.

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
php artisan preview:capture:list --json
php artisan preview:capture:show {capture}
php artisan preview:capture:doctor
php artisan preview:capture:doctor --capture={capture}
php artisan preview:capture:doctor --json
php artisan preview:capture:stats
php artisan preview:capture:stats --json
php artisan preview:capture:verify {capture}
php artisan preview:capture:verify {capture} --json
php artisan preview:capture:export {capture}
php artisan preview:capture:export {capture} --path=storage/preview/exports --json
php artisan preview:capture:replay {capture} --exact
php artisan preview:capture:replay {capture} --exact --json
php artisan preview:capture:replay {capture} --resign
php artisan preview:capture:fixture {capture}
php artisan preview:capture:fixture {capture} --json
php artisan preview:capture:test {capture}
php artisan preview:capture:test {capture} --json
php artisan preview:capture:prune --before=2026-05-01 --dry-run
php artisan preview:capture:prune --before=2026-05-01
```

Raw captures stay local. Metadata and generated fixtures redact configured sensitive
headers such as cookies and authorization values.
`preview:capture:doctor` checks capture metadata, raw body files, raw header files,
registered provider references, and redaction state without printing raw payloads or
secret header values.
`preview:capture:verify` re-runs provider verification against the stored raw body and
raw headers, so redacted metadata cannot accidentally change the verification result.
`preview:capture:stats` summarizes the local capture inventory by provider, event type,
verification state, and capture time range.
`preview:capture:export` writes a redacted metadata-only export. It does not copy raw
payloads, raw headers, or local secret-bearing files.
Capture pruning requires an explicit date cutoff and only deletes directories resolved
inside the configured capture storage root. Use `--dry-run` first when inspecting local
state.

`stripe-cli` is an optional convenience transport. It starts `stripe listen` and forwards
Stripe events to the local Preview Stripe capture endpoint. It still requires `--live`,
`preview.live_enabled=true`, a runnable Stripe CLI binary, and normal Stripe CLI auth.

Inspect configured tunnel transports without opening a tunnel:

```bash
php artisan preview:transport:list
php artisan preview:transport:list --json
php artisan preview:transport:doctor
php artisan preview:transport:doctor --json
```

`preview:transport:doctor` checks configured transport binaries without opening tunnels
or touching the network.

Generated fixture manifests can be listed without reading payload files:

```bash
php artisan preview:fixture:list
php artisan preview:fixture:list --json
php artisan preview:fixture:doctor
php artisan preview:fixture:doctor --json
```

`preview:fixture:doctor` validates fixture manifests and companion file references
without reading payload bodies or generated header fixtures.

## Route Preview

Route preview creates signed, time-limited links for named Laravel routes and proxies
execution through Laravel Preview's signed route endpoint.

```bash
php artisan preview:route:list
php artisan preview:route:list --filter=billing --json
php artisan preview:route:doctor
php artisan preview:route:doctor --json

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

`preview:route:list` inspects named route metadata and flags write-method routes without
creating signed links or executing route actions.
`preview:route:doctor` reports route-preview readiness, configured path, named route
counts, write-route warnings, supported fakes, and signing prerequisites without
executing routes.

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
    routeExpectations: [
        'billing.portal' => [
            'status' => 200,
            'output_contains' => 'Billing',
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
  --route-fake=billing.portal:mail \
  --route-status=billing.portal=200 \
  --route-output-contains="billing.portal=Billing"

php artisan preview:scenario:list
php artisan preview:scenario:list --json
php artisan preview:scenario:show subscription-renewal
php artisan preview:scenario:show subscription-renewal --json
php artisan preview:scenario:stats
php artisan preview:scenario:stats --json
php artisan preview:scenario:validate subscription-renewal
php artisan preview:scenario:validate subscription-renewal --json
php artisan preview:scenario:validate --all
php artisan preview:scenario:validate --all --json
php artisan preview:scenario:replay subscription-renewal --exact
php artisan preview:scenario:replay subscription-renewal --exact --json
php artisan preview:scenario:replay subscription-renewal --resign
php artisan preview:scenario:test subscription-renewal
```

Scenario replay runs the configured seeder through Laravel's normal seeder path, replays
listed captures, dispatches captures when `--send-to` is provided, and executes listed
routes through the same signed route-preview safety layer. Replay prints a summary of
seed, capture, dispatch, and route counts, and failures include the failing dispatch or
route when a partial result exists. Scenario fakes are forwarded to route preview; they do
not provide broader isolation than the route-preview fake flags.
`preview:scenario:stats` summarizes the local scenario inventory without loading
application state beyond scenario files and without executing seeds, routes, or captures.
`preview:scenario:validate` checks seed classes, capture references, named routes, route
parameters, and route expectation references without running seeders, executing routes, or
replaying traffic.
Configured route expectations are enforced during replay, so a route that returns the
wrong status or misses required response text fails the replay even if the response is
otherwise a 2xx.

Fixture generation writes a `manifest.json` next to each generated fixture. The manifest
contains capture metadata, signing mode, fixture context, payload locality, and safe
headers only; it excludes raw bodies and sensitive header values.

Generated scenario tests are Pest-compatible and local-first. They are meant to fail
clearly when the host app lacks required routes, models, provider secrets, seed data, or
database state. Generated scenario tests call `ScenarioRunner` directly and assert the
replay result object instead of only checking command text.
When route expectations are configured, generated tests assert the expected route status
and optional response text; otherwise they keep the default 2xx route-success assertion.

## Proof Boundary

Current proof in this repository is package-local Testbench proof plus a recorded Laravel
12 Composer path-repository smoke for package discovery, synthetic generic capture,
scenario creation, scenario replay, generated scenario test creation, and generated Pest
execution in that consumer app. A repeatable `composer smoke:consumer` script now exists
for that consumer-app proof path.
The package test suite covers capture, replay, fixture generation, provider contracts,
route preview, scenario replay, route composition, fake propagation, and generated test
syntax/structure.
CI and release workflows are present in `.github/workflows`, but hosted CI/release proof
exists only after those workflows run on GitHub for the target commit or tag.

This does not prove:

- Packagist installation.
- hosted CI for commits that have not run through GitHub Actions yet.
- SaaS, managed relay, persistent URLs, team sharing, or audit logs.
- ngrok live startup.
- full public tunnel ingress from this machine. `composer smoke:tunnel` can prove local
  tunnel startup and URL extraction. It does not prove webhook delivery unless an external
  request reaches the generated URL.
- real production provider traffic.
- Stripe CLI provider proof until `composer smoke:stripe-cli` is run with a real Stripe
  CLI session, endpoint secret, and trigger event.
- every generated scenario test shape in every consumer app.

Run local verification:

```bash
composer ci
composer release:check
composer test
```

Release and integration proof helpers:

```powershell
composer smoke:consumer
composer smoke:tunnel
$env:PREVIEW_STRIPE_ENDPOINT_SECRET = 'whsec_...'
composer smoke:stripe-cli -- -TriggerEvent checkout.session.completed
```

`composer smoke:consumer` creates a disposable Laravel app, installs `oxhq/preview`
through a Composer path repository, captures a generic request, generates a fixture and
Pest test, runs the generated test, and deletes the disposable app unless the script is
called with `-KeepWorkDir`.
`composer smoke:tunnel` proves local tunnel startup and capture URL extraction only; it
does not prove webhook delivery.
`composer smoke:stripe-cli` is the real Stripe CLI proof path and requires Stripe CLI auth
plus `PREVIEW_STRIPE_ENDPOINT_SECRET`. It redacts endpoint secrets from output.

## License

MIT

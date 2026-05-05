# Laravel Preview Roadmap

## Summary

This roadmap splits follow-up work after the v0.1 capture-to-test wedge. Later phases should not be built until the previous phase produces real usage evidence.

The product remains Laravel flow reproduction. Do not drift into competing on tunneling.

## v0.1: Capture To Test

Goal: prove that real inbound Laravel traffic can become reusable fixtures and generated Pest tests.

Ships:

- Core package foundation
- Capture module
- provider model
- generic provider
- generic HMAC provider
- Stripe reference provider
- Cloudflare Tunnel and ngrok transport adapters
- optional Stripe CLI transport adapter
- local capture storage
- exact replay
- provider-aware re-sign replay
- fixture generation
- Pest test generation

Advance only if:

- generated tests run without manual edits in supported Laravel app states
- provider behavior stays generic rather than Stripe-special-cased
- users prefer the generated fixture/test loop over tunnel plus manual copy-paste

## v0.2: Provider Expansion

Goal: prove the provider model generalizes beyond Stripe.

Candidate providers:

- GitHub (shipped v0.2)
- Shopify (shipped v0.2)
- Paddle
- Clerk
- Slack

Build providers only when users ask for them by name or a real integration needs them.

Add:

- provider contribution guide (`docs/preview/provider-contribution-guide.md`)
- provider test contract
- fixture naming conventions
- signature verification examples
- provider capability matrix (`docs/preview/provider-capability-matrix.md`)

Do not add:

- broad provider marketplace
- UI dashboard
- cloud relay
- scenario engine

Exit criteria:

- at least two non-Stripe providers pass the same capture/replay/fixture/test contract (met by GitHub and Shopify)
- provider contribution path is documented and tested
- no provider requires special branches in Core, Capture, Replay, or Testing services

## v0.3: Safe Route Preview

Goal: expose Laravel routes with Laravel-aware safety and context.

CLI target:

```bash
php artisan preview:route {route} --ttl=2h --param=id=123 --session=currency=usd --readonly-db --guard=client
php artisan preview:route {route} --ttl=2h --param=id=123 --user-id=42 --user-model="App\Models\User" --guard=web
```

v0.3 now includes shipped proxy hardening, behavior-tested fakes, and app-specific auth context.

Slice A: signed proxy execution and safety controls:

- named route lookup
- middleware summary
- TTL signed access links
- route params through repeated `--param=key=value`
- session context through repeated `--session=key=value`, carried into the proxied preview request
- guard context carried as request metadata
- default blocking of routes that do not allow `GET` or `HEAD`
- explicit non-read method opt-in
- proxy execution of the named route through the signed preview link
- `--readonly-db` transaction wrapper for covered database writes
- behavior-tested side-effect fake flags for supported queue, mail, HTTP, and event facades

Slice B: parameter, signature, warning, and audit hardening:

- required parameter failures
- optional parameter handling
- domain parameter handling
- expiry and signature tests
- clearer warnings for uncovered side effects and unsafe methods
- audit output for route, method, params, session context keys, guard metadata, TTL, safety flags, and execution mode

Slice C: behavior-tested mail fake coverage:

- prove `--fake-mail` applies Laravel mail faking during proxied route execution, not only command metadata output
- cover mail suppression with a route that would otherwise send mail
- document that mail faking does not isolate queues, events, external HTTP, cache, filesystem, or database writes unless the corresponding supported safety flag or `--readonly-db` applies

Slice D: app-specific auth context:

- add explicit authenticated preview input through `--user-id`
- allow optional `--user-model=App\Models\User` when the host app needs a concrete authenticatable model class
- keep `--guard` as guard selection and request/audit metadata, not a standalone impersonation flag
- execute auth setup through Laravel's normal app auth stack for the selected guard and user model
- fail clearly when the user cannot be resolved, the model is not authenticatable, or the selected guard cannot authenticate that user
- avoid any claim of generic authorization bypass, policy bypass, middleware bypass, or full scenario isolation

Do not call it `--readonly`. `--readonly-db` is not a complete read-only guarantee: it only covers database writes inside the wrapped preview request. It does not cover queues, mail, cache, filesystem writes, external HTTP calls, or events unless explicit fake flags are used for the side effects the package supports.

`--guard` remains guard selection plus request metadata. Do not claim full auth, generic guard switching, authorization bypass, or user impersonation beyond the explicit app-specific `--user-id` auth context that is behavior-tested.

Route preview does not provide filesystem isolation or cache isolation.

Safety flags:

```bash
--fake-queue
--fake-mail
--fake-http
--fake-events
```

Advance only if users ask for safe demo sharing, client review links, or route-specific local previews.

## v1.0: Scenario Workbench

Goal: compose captures, route previews, seeded state, fakes, and assertions into reusable team flows.

Foundation CLI:

```bash
php artisan preview:scenario:list
php artisan preview:scenario:show subscription-renewal
```

Scenario example:

```php
use App\Database\Seeders\DemoSubscriptionSeeder;
use Oxhq\Preview\Scenario\Scenario;

return new Scenario(
    name: 'subscription-renewal',
    seed: DemoSubscriptionSeeder::class,
    routes: ['billing.portal'],
    captures: ['20260505011852323-sugxujb2'],
    fakes: ['queue', 'mail'],
    notes: 'Exercises the local renewal review flow after a captured provider callback.',
);
```

Foundation slice:

- scenario file format
- local PHP scenario files under `preview/scenarios` or configured `preview.scenario_path`
- scenario repository that loads files returning `Oxhq\Preview\Scenario\Scenario`
- capture ID composition
- named route composition
- queue/mail/event/http fake configuration metadata
- optional seed class metadata
- optional notes
- `preview:scenario:list`
- `preview:scenario:show {scenario}`

Later v1.0 slices:

- seeded state execution hooks
- capture replay composition
- route preview execution composition
- scenario replay command
- scenario test generation

Do not claim complete scenario replay, seed composition, or scenario test generation until those later slices are implemented and verified. The foundation slice is local-first Laravel flow cataloging and inspection.

Advance only if users ask to save and replay complete flows, not just individual captures.

## SaaS Trigger

Do not build SaaS because a relay is technically possible. Build it only after repeated user pain around local-only workflow limits.

Potential SaaS:

- persistent URLs
- team-shared scenarios
- cloud replay
- CI replay
- audit logs
- managed relay

SaaS trigger signals:

- users complain about rotating URLs unprompted
- teams need shared scenario libraries
- CI replay becomes a requested workflow
- audit logs matter for team adoption

Do not build before:

- v0.1 proves capture-to-test
- provider model generalizes
- fixture format becomes useful in real repos

## Failure Gates

Stop or rethink if:

- users only use the tunnel URL and ignore fixtures/tests
- generated tests are brittle in real Laravel apps
- provider support becomes a pile of one-off special cases
- route preview competes only with Valet/Herd/Expose sharing
- scenarios require too much ceremony before users have repeated flows worth saving

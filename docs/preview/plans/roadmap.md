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

- GitHub
- Shopify
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

- at least two non-Stripe providers pass the same capture/replay/fixture/test contract
- provider contribution path is documented and tested
- no provider requires special branches in Core, Capture, Replay, or Testing services

## v0.3: Safe Route Preview

Goal: expose Laravel routes with Laravel-aware safety and context.

CLI target:

```bash
php artisan preview:route billing.portal --ttl=2h --readonly-db
php artisan preview:route checkout.success --guard=client
```

Build:

- named route lookup
- middleware summary
- TTL access links
- signed guest access
- `--readonly-db` transaction rollback for covered database writes
- explicit warnings for side effects outside the database

Do not call it `--readonly`. Database rollback does not protect queues, mail, cache, filesystem writes, external HTTP calls, or events.

Future safety flags:

```bash
--fake-queue
--fake-mail
--fake-http
--fake-events
```

Advance only if users ask for safe demo sharing, client review links, or route-specific local previews.

## v1.0: Scenario Workbench

Goal: compose captures, route previews, seeded state, fakes, and assertions into reusable team flows.

CLI target:

```bash
php artisan preview:scenario subscription-renewal
php artisan preview:scenario replay subscription-renewal
php artisan preview:scenario list
```

Scenario example:

```php
return new Scenario(
    name: 'subscription-renewal',
    seed: DemoSubscriptionSeeder::class,
    routes: ['billing.portal'],
    captures: ['stripe:invoice.payment_succeeded'],
    fakes: ['queue', 'mail'],
);
```

Build:

- scenario file format
- seeded state hooks
- capture replay composition
- route preview composition
- queue/mail/event/http fake configuration
- scenario replay command
- scenario test generation

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

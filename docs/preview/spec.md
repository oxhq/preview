# Laravel Preview Specification

## Summary

`oxhq/preview` is a local-first Laravel developer package for flow reproduction.
It captures real inbound Laravel traffic, preserves provider and application context, replays it locally, and turns it into reusable fixtures and Pest tests.

Laravel Preview is not a tunneling product, a hosted request bin, or a Stripe-specific webhook debugger. Tunnels are disposable transport. The product starts after traffic reaches the Laravel application.

The core loop is:

```text
receive -> verify -> capture -> replay -> generate fixture -> generate test -> reuse
```

## Positioning

Laravel Preview is Laravel's local flow reproduction workbench.

It is not:

- an ngrok alternative
- an Expose replacement
- a Webhook.site clone
- a Stripe-only tool
- a hosted request bin
- a generic tunnel manager

It is:

- a capture-to-test workflow for real Laravel traffic
- a local fixture generator for provider callbacks and inbound requests
- a replay engine for captured request flows
- the foundation for future reusable Laravel scenarios

Short public-safe line:

> Laravel Preview captures real Laravel traffic and turns it into replayable fixtures, generated Pest tests, and eventually reusable application scenarios.

Against generic request bins:

> Webhook.site shows what happened. Laravel Preview turns what happened into Laravel test assets.

Against tunnel tools:

> ngrok and Expose get traffic to your app. Laravel Preview makes that traffic reproducible inside Laravel.

Against provider CLIs:

> Stripe CLI helps receive Stripe events. Laravel Preview makes provider traffic reusable across Laravel tests and scenarios.

## Product Thesis

The moat is not tunneling. The moat is Laravel-native reproduction.

Laravel Preview should know and preserve Laravel-specific context:

- request raw body requirements
- provider signatures
- named endpoints and routes
- middleware behavior
- guard/session context where applicable
- Pest testing conventions
- local fixture storage
- queue, mail, event, and HTTP fake boundaries in later phases
- seeded scenario state in later phases

Generic tunnel and request-bin tools can move and inspect traffic. Laravel Preview should convert traffic into durable Laravel development assets.

## Package Structure

The package ships as one Composer package with internal modules.

```text
oxhq/preview
├── src/
│   ├── Core/
│   ├── Capture/
│   ├── Providers/
│   ├── Testing/
│   ├── Route/
│   └── Scenario/
├── config/
│   └── preview.php
└── PreviewServiceProvider.php
```

Do not split packages before usage proves that teams want modules independently. The product loop is sequential: captures feed fixtures, fixtures feed tests, and tests/scenarios feed replay.

## Core Module

`Preview\Core` owns shared package infrastructure:

- Laravel service provider
- config publishing
- storage path resolution
- provider registry
- transport registry
- capture ID generation
- redaction policy
- live-mode safety checks

Transport interface:

```php
interface TunnelTransport
{
    public function open(string $localUrl): TunnelHandle;

    public function close(TunnelHandle $handle): void;
}
```

v0.1 supports user-owned transport:

- Cloudflare Tunnel
- ngrok

Provider-specific transports are allowed as optional convenience adapters. For example, Stripe CLI may exist for Stripe capture, but it must not define the product architecture.

The v0.1 transport registry exposes `cloudflare` and `ngrok` adapters. This is package-level proof until real binaries, account state, and live tunnel startup are verified on a developer machine.

## Capture Module

`Preview\Capture` is the v0.1 center of gravity.

It owns:

- inbound request normalization
- raw body preservation
- header capture
- method, path, query, and timestamp capture
- provider name and provider verification result
- local capture persistence
- listing and showing captures
- replay dispatch

Replay modes:

```bash
php artisan preview:capture:replay {capture} --exact
php artisan preview:capture:replay {capture} --resign
php artisan preview:capture:replay {capture} --exact --send-to=https://local-app.test
```

`--exact` sends the captured body and captured headers.

`--resign` sends the captured body with fresh provider-valid headers when the provider supports signing.

This distinction is required because some providers use timestamped signatures. Exact replay is useful for debugging. Re-signed replay is useful for durable tests.

Without `--send-to`, replay remains summary-only and prints the payload shape. With `--send-to`, Laravel Preview sends the captured method, path, query, headers, and raw body to the target base URL or full URL.

## Providers Module

Providers describe how captured traffic is understood.

```php
interface PreviewProvider
{
    public function name(): string;

    /** @return list<ProviderCapability> */
    public function capabilities(): array;

    public function verify(PreviewRequest $request): VerificationResult;

    public function eventType(PreviewRequest $request): ?string;

    public function fixtureName(PreviewRequest $request): string;

    public function canSign(): bool;

    /** @return array<string, string> */
    public function sign(string $rawBody, array $headers = []): array;
}
```

Provider capabilities:

```php
enum ProviderCapability
{
    case VerifiesSignature;
    case ExtractsEventType;
    case ReSignsPayload;
    case GeneratesFixture;
    case GeneratesTest;
}
```

v0.1 providers:

- `GenericProvider`
- `GenericHmacProvider`
- `StripeProvider`

Stripe is the first high-fidelity reference provider because it proves timestamped signatures, raw body fidelity, event extraction, fixture generation, and re-signing. It is not the product center.

## Testing Module

`Preview\Testing` owns generated Laravel test assets:

- fixture files
- Pest test files
- raw payload access
- header access
- fresh signed headers when supported
- endpoint assertions

Generic target output:

```php
it('handles captured webhook traffic', function () {
    $event = PreviewFixture::generic('order-created');

    $this->postRawJson(
        '/webhooks/orders',
        $event->rawBody(),
        $event->headers()
    )->assertOk();
});
```

Stripe reference output:

```php
it('handles stripe checkout.session.completed', function () {
    $event = PreviewFixture::stripe('checkout.session.completed');

    $this->postRawJson(
        '/webhook/stripe',
        $event->rawBody(),
        $event->freshSignedHeaders()
    )->assertOk();
});
```

Generated tests should pass when the target Laravel app has the required state. If required app state is missing, generated output should fail clearly and point to missing preconditions rather than hiding the problem behind vague fixture code.

## Fixture Format

The fixture format is the moat seed. It must be versionable, reusable, and provider-aware.

Example:

```php
return PreviewFixture::provider('stripe')
    ->event('checkout.session.completed')
    ->endpoint('/webhook/stripe')
    ->rawBody(__DIR__.'/payload.json')
    ->headers(__DIR__.'/headers.php')
    ->signing('resign')
    ->assertsOk();
```

The same fixture should later power:

- replay
- Pest generation
- local scenarios
- CI replay
- team sharing
- cloud replay if SaaS demand appears

## v0.1 CLI

```bash
php artisan preview:capture generic
php artisan preview:capture hmac --signature-header=X-Signature
php artisan preview:capture stripe

php artisan preview:capture:list
php artisan preview:capture:show {capture}

php artisan preview:capture:replay {capture} --exact
php artisan preview:capture:replay {capture} --resign
php artisan preview:capture:replay {capture} --exact --send-to=https://local-app.test

php artisan preview:capture:fixture {capture}
php artisan preview:capture:test {capture}
```

Optional provider-specific transport:

```bash
php artisan preview:capture stripe --transport=stripe-cli
```

That command is a convenience path, not the default architecture.

## v0.1 Success Criteria

v0.1 exists only if these criteria work:

| # | Criterion |
|---|---|
| 1 | Capture real inbound traffic through Cloudflare Tunnel or ngrok |
| 2 | Capture generic webhook traffic and generate a fixture |
| 3 | Capture HMAC-signed traffic and verify it |
| 4 | Capture Stripe traffic as the high-fidelity reference provider |
| 5 | Replay supports exact mode and provider-aware re-signing |
| 6 | Generate a Pest test that runs without manual edits in a supported Laravel app state |

Capture alone is not enough. Replay alone is not enough. The wedge exists when traffic becomes reusable Laravel test assets.

## Security Defaults

Laravel Preview must be conservative by default:

- captures are stored locally
- raw captures are gitignored by default
- generated fixtures are opt-in to commit
- cookies are redacted by default
- authorization headers are redacted by default
- provider secrets are never printed
- live provider traffic requires explicit `--live`
- capture storage is configurable
- public exposure has TTL by default
- destructive route exposure requires explicit confirmation in later phases

Trust is part of the product. "Local-first" must be enforced by behavior, not only claimed in copy.

## Route Module

`Preview\Route` is not part of v0.1. It ships only after capture-to-test proves demand.

Future CLI:

```bash
php artisan preview:route billing.portal --ttl=2h --readonly-db
php artisan preview:route checkout.success --guard=client
```

Use `--readonly-db`, not `--readonly`.

Database transaction rollback does not protect queues, mail, cache, filesystem writes, external HTTP calls, or events.

Future safety flags:

```bash
--fake-queue
--fake-mail
--fake-http
--fake-events
```

## Scenario Module

`Preview\Scenario` is the long-term moat. It composes captures, routes, seeded state, fakes, and assertions into reusable team artifacts.

Future CLI:

```bash
php artisan preview:scenario subscription-renewal
php artisan preview:scenario replay subscription-renewal
```

Example scenario:

```php
return new Scenario(
    name: 'subscription-renewal',
    seed: DemoSubscriptionSeeder::class,
    routes: ['billing.portal'],
    captures: ['stripe:invoice.payment_succeeded'],
    fakes: ['queue', 'mail'],
);
```

Scenarios should be built after fixtures and replay prove useful in real apps.

## Open-Core Path

OSS should include:

- local capture
- providers
- verification
- replay
- fixtures
- Pest generation
- local route safety later
- local scenarios later

SaaS should wait for demand:

- persistent URLs
- team scenario sharing
- cloud replay
- CI replay
- audit logs
- managed relay

The SaaS trigger is user pain around unstable URLs, team sharing, or CI replay. The trigger is not simply that a relay can be built.

## Failure Conditions

The product fails if:

1. Developers still use tunnel/request-bin/manual-copy after trying generated fixtures.
2. Generated Pest tests are too brittle for real Laravel apps.
3. Provider support becomes Stripe-special-cased instead of validating the provider model.
4. Laravel Preview only captures traffic but does not make it reproducible.
5. The package starts competing on tunneling instead of Laravel-native reproduction.

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
- request metadata and session context where applicable
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

Do not split packages before usage proves that teams want modules independently. The product loop is sequential: captures feed fixtures, fixtures feed tests, and later scenarios can compose those proven assets.

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

Windows installs may place tunnel binaries outside PATH. The package supports explicit binary configuration:

```env
PREVIEW_CLOUDFLARED_BINARY="C:\Program Files (x86)\cloudflared\cloudflared.exe"
PREVIEW_NGROK_BINARY="C:\Tools\ngrok.exe"
```

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

Provider expansion guidance lives in `docs/preview/provider-contribution-guide.md`, and current versus candidate capability status lives in `docs/preview/provider-capability-matrix.md`. Candidate providers are not supported until implemented and verified.

```php
interface PreviewProvider
{
    public function name(): string;

    /** @return list<ProviderCapability> */
    public function capabilities(): array;

    public function verify(PreviewRequest $request): VerificationResult;

    public function eventType(PreviewRequest $request): ?string;

    public function fixtureName(PreviewRequest $request): string;

    /** @return array<string, mixed> */
    public function fixtureContext(PreviewRequest $request): array;

    public function canSign(): bool;

    /** @return array<string, string> */
    public function sign(string $rawBody, array $headers = []): array;
}
```

Providers that need runtime/query/fixture settings may also implement
`Oxhq\Preview\Providers\ContextualPreviewProvider`:

```php
interface ContextualPreviewProvider extends PreviewProvider
{
    /** @param array<string, mixed> $context */
    public function withRuntimeContext(array $context): PreviewProvider;
}
```

`ProviderRegistry::get($name, $context)` applies this optional hook before the
provider is used. Providers should use it for provider-owned settings such as
signature header names, HMAC algorithms, webhook versions, or event header
names. Services should not add provider-specific branches for those settings.

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

v0.2 providers:

- `GitHubProvider`
- `ShopifyProvider`

These prove the provider model beyond Stripe without adding provider-specific branches to Core, Capture, Replay, or Testing services.

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
php artisan preview:capture generic --transport=cloudflare --local-url=http://127.0.0.1:8000
php artisan preview:capture generic --transport=cloudflare --local-url=http://127.0.0.1:8000 --wait
php artisan preview:capture generic --transport=cloudflare --local-url=http://127.0.0.1:8000 --hold-seconds=60

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

Inbound capture endpoint:

```text
/__preview/capture/{provider}
```

The endpoint accepts common HTTP methods, stores a local capture, and returns safe JSON metadata only. It does not return raw body or captured headers. If `X-Preview-Original-Path` is present, that value is stored as the captured application path; otherwise the package route path is stored.

Tunnel capture defaults to open-print-close so automated checks do not hang. Use `--wait` for an interactive session that stays open until Enter, or `--hold-seconds` for a bounded smoke/demo window.

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

`Preview\Route` starts in v0.3 as Safe Route Preview. It is not part of v0.1 or v0.2.

The route preview command generates signed, time-limited access to a named Laravel route:

```bash
php artisan preview:route {route} --ttl=2h --param=id=123 --session=currency=usd --readonly-db --guard=client
php artisan preview:route {route} --ttl=2h --param=id=123 --user-id=42 --user-model="App\Models\User" --guard=web
```

Example:

```bash
php artisan preview:route billing.portal --ttl=2h --param=id=123 --session=currency=usd --readonly-db --guard=client
```

Use `--readonly-db`, not `--readonly`.

v0.3 route preview scope:

- named route lookup
- middleware summary
- TTL signed access links
- route parameters from repeated `--param=key=value` flags
- session context from repeated `--session=key=value` flags, carried into the proxied preview request
- required, optional, and domain parameter validation
- guard context recorded as request metadata through `--guard`
- default blocking for routes that do not allow `GET` or `HEAD`
- explicit opt-in before non-`GET`/`HEAD` routes can be exposed
- proxied execution of the named route through the signed preview link
- `--readonly-db` transaction rollback for covered database writes inside the wrapped request
- behavior-tested side-effect fake flags for supported queue, mail, HTTP, and event facades
- behavior-tested mail fake coverage that proves route preview suppresses Laravel mail side effects when `--fake-mail` is present
- app-specific auth context using `--user-id` plus optional `--user-model`, where `--guard` selects or records the guard context but does not by itself authenticate a user
- expiry, signature, parameter, warning, and audit-output tests

`--readonly-db` is not a complete read-only guarantee. It only covers database writes performed inside the wrapped preview request transaction.

Database transaction rollback does not protect queues, mail, cache, filesystem writes, external HTTP calls, or events unless the specific side effect is covered by an explicit fake flag.

`--guard` does not by itself impersonate an authenticated user. Authenticated route preview must be explicit and app-specific: `--user-id` identifies an application user, optional `--user-model` resolves the model class when the app cannot rely on the default authenticatable model, and `--guard` is still the guard selection plus audit metadata. This design must not claim generic authorization bypass, policy bypass, middleware bypass, or complete scenario isolation.

Route preview does not provide filesystem isolation or cache isolation.

Safety flags:

```bash
--fake-queue
--fake-mail
--fake-http
--fake-events
```

## Scenario Module

`Preview\Scenario` is the long-term moat. It composes captures, named routes, seeded state, fakes, notes, and eventually assertions into reusable local Laravel flow artifacts.

The first v1.0 foundation slice is deliberately smaller than full replay. It introduces local PHP scenario files and read-only discovery commands so teams can name and inspect flows before Laravel Preview executes them end to end.

```bash
php artisan preview:scenario:list
php artisan preview:scenario:show subscription-renewal
```

Example scenario:

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

Scenario files are regular PHP files stored under `preview/scenarios` by default, or the app's configured `preview.scenario_path`. Each file must return an `Oxhq\Preview\Scenario\Scenario` instance. The foundation schema composes:

- `name`: stable local scenario name used by list/show commands
- `captures`: capture IDs from local capture storage
- `routes`: Laravel route names for future route-preview composition
- `fakes`: supported fake boundaries such as queue, mail, HTTP, and events
- `seed`: optional Laravel seeder class name
- `notes`: optional human context for the flow

The foundation slice started as local catalog and inspection. Current package proof adds seed execution and capture replay composition, while route-preview composition and scenario test generation remain later v1.0 work.

Executable Scenario slices are sequenced narrowly:

1. Seed composition runs the configured seeder through Laravel's normal seeder path before any replay work. Seeder failures stop the scenario and report the failing seeder class.
2. Capture replay composition adds `preview:scenario:replay {scenario}` and reuses existing capture replay behavior for each listed capture.
3. Route composition executes named route previews only after the route-preview safety flags and boundaries are applied to scenario replay.
4. Scenario test generation emits Pest-compatible tests only after seed, capture replay, and route composition are behavior-tested together.

Scenario replay must preserve the existing capture replay semantics:

```bash
php artisan preview:scenario:replay subscription-renewal --exact
php artisan preview:scenario:replay subscription-renewal --resign
```

`--exact` replays each listed capture with its stored raw body and captured headers. `--resign` replays each listed capture with its stored raw body and fresh provider-valid signature headers when the provider supports signing. If a provider cannot re-sign, the command must fail clearly instead of silently falling back to exact replay.

Route composition and scenario test generation are not complete unless the commands, package tests, and consumer evidence prove them. Documentation should continue to call route names future composition metadata until those later slices ship.

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

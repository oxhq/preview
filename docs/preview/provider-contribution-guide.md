# Provider Contribution Guide

Internal guide for adding a Laravel Preview provider. Do not add a provider because it is popular; add it when a user names it or a real integration needs it.

## Provider Contract

Implement `Oxhq\Preview\Providers\PreviewProvider`:

```php
interface PreviewProvider
{
    public function name(): string;
    public function capabilities(): array;
    public function verify(PreviewRequest $request): VerificationResult;
    public function eventType(PreviewRequest $request): ?string;
    public function fixtureName(PreviewRequest $request): string;
    public function fixtureContext(PreviewRequest $request): array;
    public function canSign(): bool;
    public function sign(string $rawBody, array $headers = []): array;
}
```

Register the provider through `preview.providers`. Core, Capture, Replay, and Testing services must keep using the provider interface; do not add provider-specific branches outside the provider.

Providers that need runtime/query/fixture settings may also implement
`Oxhq\Preview\Providers\ContextualPreviewProvider`:

```php
interface ContextualPreviewProvider extends PreviewProvider
{
    public function withRuntimeContext(array $context): PreviewProvider;
}
```

`ProviderRegistry::get($name, $context)` applies the hook when the provider
supports it. Use this for provider-owned settings such as HMAC signature header
names, signing algorithms, webhook versions, or event type header names.

## Implementation Rules

- `name()` returns the stable config and fixture segment, such as `github`.
- `capabilities()` must match working behavior, not planned behavior.
- `verify()` validates against the raw body and captured headers. Return skipped only when the provider has no signature concept.
- `eventType()` extracts the provider event name when available.
- `fixtureName()` returns a deterministic, filesystem-safe name.
- `fixtureContext()` may store non-secret replay metadata, such as signature header name, algorithm, webhook version, or event header name.
- `withRuntimeContext()` may apply non-secret runtime/query/fixture settings when the provider implements `ContextualPreviewProvider`.
- `canSign()` returns true only when `sign()` can generate provider-valid fresh headers from the raw body.
- `sign()` returns only headers needed for fresh replay. It must not mutate the payload or read secrets from fixture files.

## Verification And Signing

Signature verification must use the exact raw body. Do not JSON-decode and re-encode before verifying or signing.

Timestamped signatures should reject stale captures during live verification when the provider requires it. Re-signed replay should create fresh timestamped headers.

Header lookup should be case-insensitive. If the provider supports multiple signature formats, normalize them inside the provider and cover each accepted format in tests.

## Fixture Context

Use `fixtureContext()` for replay configuration that is safe to commit. Good examples:

- signature header name
- hash algorithm
- event type header name
- API version header

Do not store:

- webhook secrets
- bearer tokens
- cookies
- provider account IDs when they are sensitive in the target app
- raw captured headers that are already redacted elsewhere

If the provider needs a secret for fresh signing, read it from Laravel config or environment at runtime.

Runtime context is for behavior, not storage. For example, an HMAC provider can
use context to choose `X-Signature` and `sha256` during verification or
re-signing, then use `fixtureContext()` to persist those non-secret choices for
future replay.

## Redaction And Secrets

Add provider secret headers to `preview.redact_headers` when the provider introduces one. Generated committed fixtures must not contain authorization headers, cookies, set-cookie headers, provider secrets, or redacted values.

Raw captures stay local-first. If a capture contains sensitive headers, generated payloads may be placed under the local-only fixture area; do not work around that behavior to make a prettier fixture tree.

Never print provider secrets in command output, test failure messages, fixture metadata, or docs examples.

## Test Expectations

Every new provider needs focused tests for:

- capability list
- valid signature verification
- invalid signature rejection
- stale timestamp rejection when applicable
- event type extraction
- deterministic fixture naming
- `fixtureContext()` safety
- fresh signing when `canSign()` is true
- unsupported signing behavior when `canSign()` is false

Add capture/replay/fixture/test coverage that proves the provider works through the same contract as Generic, HMAC, and Stripe. The exit bar for v0.2 is at least two non-Stripe providers passing that shared contract without service-level special cases.

# Changelog

All notable user-facing changes for `oxhq/preview` will be documented here.

## Unreleased

- Added the package-local MVP surface for Laravel Preview: provider discovery and doctor commands, local capture endpoints, capture replay/export/test helpers, route preview commands, fixture generation, scenario files, and Pest-compatible generated tests.
- Added local provider support for generic webhooks, HMAC-signed generic webhooks, Stripe-shaped events, Shopify-shaped events, and GitHub-shaped events.
- Added local transport checks for configured process tunnels, ngrok, Cloudflare Tunnel, and Stripe CLI forwarding.
- Added provider sample generation, provider scaffolding, provider self-tests, capture integrity hashes, capture comparison, capture bundles, capture timeline summaries, fixture export, fixture inventory stats, route metadata export, scenario metadata export, scenario bundles, and source archive hygiene checks.
- Added CI, Dependabot, release workflow, clean consumer smoke, Packagist install smoke, tunnel startup smoke, Stripe CLI smoke path, GitHub/Packagist release checkers, release archive checks, source archive hygiene, security/support docs, guarded release-prep helper, and a release-surface checker so the package can distinguish local package readiness from published release proof.

This unreleased state is package-local. It does not claim Packagist visibility, hosted CI results, live provider delivery, or production webhook proof until those release checks are completed and recorded.

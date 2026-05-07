# Changelog

All notable user-facing changes for `oxhq/preview` will be documented here.

## Unreleased

## v0.1.1 - 2026-05-07

- Added a hosted Packagist install smoke workflow for published package checks across
  PHP 8.3, PHP 8.4, Laravel 11, and Laravel 12.
- Clarified the README quickstart around the published `oxhq/preview` package and
  removed path-repository install guidance from the public onboarding path.
- Recorded Laravel 11 and Laravel 12 Packagist smoke commands as the consumer install
  checks for published releases.

## v0.1.0 - 2026-05-06

- Added the package-local MVP surface for Laravel Preview: provider discovery and doctor commands, local capture endpoints, capture replay/export/test helpers, route preview commands, fixture generation, scenario files, and Pest-compatible generated tests.
- Added local provider support for generic webhooks, HMAC-signed generic webhooks, Stripe-shaped events, Shopify-shaped events, and GitHub-shaped events.
- Added local transport checks for configured process tunnels, ngrok, Cloudflare Tunnel, and Stripe CLI forwarding.
- Added provider sample generation, provider scaffolding, provider self-tests, capture integrity hashes, capture comparison, capture bundles, capture timeline summaries, fixture export, fixture inventory stats, route metadata export, scenario metadata export, scenario bundles, and source archive hygiene checks.
- Added CI, Dependabot, release workflow, clean consumer smoke, Packagist install smoke, tunnel startup smoke, Cloudflare Tunnel smoke alias, signed Stripe/GitHub provider smoke, Stripe CLI smoke path, GitHub/Packagist release checkers, release archive checks, source archive hygiene, security/support docs, guarded release-prep helper, and a release-surface checker so the package can distinguish local package readiness from published release proof.
- Improved the Stripe CLI smoke so it can use a local Stripe executable path, derive the listener signing secret without printing it, start a local Testbench server, and verify that a triggered Stripe event becomes a verified Preview capture.
- Added public-ingress smoke coverage, live GitHub webhook delivery smoke through `gh`, PowerShell script parsing checks, and expanded signed-provider smoke coverage for Shopify.
- Added a hosted CI matrix for PHP 8.3 and 8.4 across Laravel 11 and 12 dependency lanes, plus a Windows PowerShell parser check.
- Added optional Packagist sync automation for release workflows, webhook-mode Packagist visibility waiting, GitHub release asset/checksum verification, and a safer release-prep command that does not create tags unless explicitly requested.
- Expanded the clean consumer smoke to cover scenario creation, scenario replay, generated scenario tests, and generated capture tests in a disposable Laravel app.
- Expanded live provider smoke helpers with GitHub ping/push proof modes and Shopify dry-run, CLI trigger, and Admin GraphQL subscription modes.

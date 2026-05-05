# Release Checklist

Use this checklist before publishing `oxhq/preview` as a Composer package.

## Pre-Release

- Run local package checks from the repository root:
  - `composer ci`
- Run a clean consumer smoke in a separate Laravel app using a local path repository for `oxhq/preview`:
  - `composer smoke:consumer`
- In the consumer app, confirm the service provider registers and the core commands run:
  - `php artisan preview:doctor`
  - `php artisan preview:provider:list`
  - `php artisan preview:transport:list`
  - `php artisan preview:route:list`
- Run a tunnel startup smoke with a local tunnel binary:
  - `composer smoke:tunnel`
- Run the Stripe CLI proof path against the consumer app:
  - set `PREVIEW_STRIPE_ENDPOINT_SECRET`
  - run `composer smoke:stripe-cli -- -TriggerEvent checkout.session.completed`
  - confirm Laravel Preview captures the event locally
  - replay or generate a test from the captured event
- Confirm GitHub Actions CI is green for the exact commit that will be tagged.

## Publish

- Tag the release from the verified commit.
- Push the tag to GitHub.
- Confirm Packagist sees `oxhq/preview` and exposes the new version.

## Post-Release Verification

- Install the tagged version in a clean consumer Laravel app with `composer require --dev oxhq/preview:<version>`.
- Run `php artisan preview:doctor` and one capture or route-preview smoke from that clean install.
- Confirm the README, changelog, release notes, and Packagist metadata describe only proven behavior.

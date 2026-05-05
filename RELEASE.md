# Release Checklist

Use this checklist before publishing `oxhq/preview` as a Composer package.

## Pre-Release

- Run local package checks from the repository root:
  - `composer ci`
  - `composer release:dist`
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
- Prepare the tag locally after the worktree is clean and hosted CI is green:
  - `composer release:prepare -- -Version v0.1.0`

## Publish

- Push the verified tag:
  - `composer release:prepare -- -Version v0.1.0 -PushTag`
- Confirm the GitHub Release and tag state:
  - `composer release:github -- -Version v0.1.0`
- Confirm Packagist sees `oxhq/preview` and exposes the new version:
  - `composer release:packagist -- v0.1.0`

## Post-Release Verification

- Install the tagged version in a clean consumer Laravel app from Packagist:
  - `composer smoke:packagist-install -- -Version v0.1.0`
- Run `php artisan preview:doctor` and one capture or route-preview smoke from that clean install.
- Confirm the README, changelog, release notes, and Packagist metadata describe only proven behavior.

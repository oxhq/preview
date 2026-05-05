# Support

## Questions and Bugs

Use GitHub Issues for public questions, bug reports, and feature requests:

https://github.com/oxhq/preview/issues

Before opening a new issue, check existing issues and the README for the current install and usage notes.

Do not paste provider secrets, signing keys, access tokens, customer data, or raw webhook payloads into public issues. Redact sensitive values or replace them with synthetic examples.

## Helpful Diagnostics

For bugs, include:

- `oxhq/preview` version and install method
- PHP version
- Laravel version
- Operating system
- The Preview command, route, provider, or transport involved
- The smallest reproduction you can share
- Redacted output from relevant diagnostics, such as `preview:config`, `preview:provider:doctor`, `preview:transport:doctor`, `preview:capture:verify`, or `preview:route:doctor`

If the issue involves a webhook provider, describe the provider, event type, and signature mode without sharing live secrets or raw production payloads.

## Proof Boundaries

Local command output proves behavior in the application and machine where it ran. It does not prove hosted CI, Packagist availability, GitHub release state, provider account configuration, tunnel reliability, or production traffic handling.

When reporting a problem, say which boundary failed: local install, local capture, fixture generation, replay, generated test, tunnel transport, provider verification, package install, CI, or release distribution.

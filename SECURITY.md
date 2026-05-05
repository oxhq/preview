# Security Policy

## Supported Versions

`oxhq/preview` is pre-1.0 software. Until a stable 1.0 release exists, security fixes are targeted at the latest published release line only. If you are testing an older pre-1.0 release, upgrade to the latest version before reporting an issue unless the issue also reproduces there.

Breaking changes may ship in pre-1.0 releases when needed to close a security gap or remove an unsafe default.

## Reporting a Vulnerability

Please do not report security vulnerabilities in public issues.

Use GitHub Security Advisories for `oxhq/preview` if the repository exposes private vulnerability reporting. If that path is not available, contact the oxhq maintainers privately through the GitHub organization profile:

https://github.com/oxhq

Include a minimal reproduction, affected version, expected impact, and any relevant environment details. Do not include real provider secrets, signing keys, access tokens, customer data, or raw webhook payloads. Use redacted examples or synthetic payloads instead.

We do not publish a fixed response SLA for this project yet. Maintainers will triage credible reports as availability allows and will coordinate fixes privately when disclosure timing matters.

## Security Stance

Preview is designed as a local-first Laravel development tool. Captures, replay payloads, fixtures, and generated tests are stored and run in your application environment. The package does not operate a cloud service for uploading captured provider payloads.

Treat captures and fixtures as sensitive application data. Keep generated local payload files out of public repositories, avoid posting webhook bodies in issues, and rotate any provider secret that may have been exposed during testing.

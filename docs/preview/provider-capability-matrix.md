# Provider Capability Matrix

Internal matrix for v0.2 provider expansion. This is a planning and contribution surface, not a support claim.

| Provider | Status | Verifies signature | Extracts event type | Re-signs payload | Generates fixture | Generates Pest test | Notes |
|---|---|---:|---:|---:|---:|---:|---|
| Generic | Shipped v0.1 | No | Yes | No | Yes | Yes | Uses `X-Preview-Event`; no provider signature semantics. |
| Generic HMAC | Shipped v0.1 | Yes | Yes | Yes | Yes | Yes | Configurable signature header, shared secret, and algorithm. |
| Stripe | Shipped v0.1 reference | Yes | Yes | Yes | Yes | Yes | High-fidelity reference for timestamped signatures and raw body replay. |
| GitHub | Candidate | TBD | TBD | TBD | TBD | TBD | Build only after named user demand or a real integration requires it. |
| Shopify | Candidate | TBD | TBD | TBD | TBD | TBD | Build only after named user demand or a real integration requires it. |
| Paddle | Candidate | TBD | TBD | TBD | TBD | TBD | Build only after named user demand or a real integration requires it. |
| Clerk | Candidate | TBD | TBD | TBD | TBD | TBD | Build only after named user demand or a real integration requires it. |
| Slack | Candidate | TBD | TBD | TBD | TBD | TBD | Build only after named user demand or a real integration requires it. |

## Matrix Rules

- `Yes` means the capability exists in code and has tests.
- `No` means the provider intentionally does not support that capability.
- `TBD` means no provider has been implemented or verified yet.
- Candidate rows must not appear in public copy as supported providers.
- A provider moves out of Candidate only after capture, replay, fixture generation, and Pest test generation all pass through the shared provider contract.

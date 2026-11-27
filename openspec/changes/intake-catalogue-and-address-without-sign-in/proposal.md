# Proposal: intake-catalogue-and-address-without-sign-in

## Why

live-portaliq-1 (25 Nov 2026, 18:51Z) found that `/portal/api/intake/catalogue` and `/portal/api/intake/address` are `#[PublicPage]`, rate limited and documented as public, yet `PortalIntakeController` is `PortalProtected`, so `PortalAuthMiddleware` answers 401 to a signed-out visitor. The boards draw a signed-out catalogue, and an anonymous form needs the address lookup. Ruben decided (25 Nov 2026 21:50 CET): the catalogue and address read methods join `PortalAuthMiddleware`'s anonymous list, rate limited.

## What changes

- `PortalAuthMiddleware` keeps a per-controller list of public reads: `PortalIntakeController::catalogue` and `::address`. Without a session these pass the gate; their own `#[AnonRateLimit]` (60 and 30 per minute per client) stays the limit.
- Every other intake method (form, submit, family, pay, status, on-behalf, ...) keeps the bearer gate.
- The list is bound to the controller class: a method of the same name on another guarded controller keeps the gate.

## Out of scope

- The intake `form` route without a session (not part of the decision).
- opencatalogi's publication schema regaining `portal`/`topic`/`route` (opencatalogi's change, same decision batch).

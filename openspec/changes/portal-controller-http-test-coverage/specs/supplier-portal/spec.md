---
status: proposed
---

# Spec: supplier-portal (controller HTTP test coverage)

## ADDED Requirements

### Requirement: Every portal HTTP controller MUST have automated tests exercising its actual responses, not only its delegate services

`ContributionController`, `SessionController`, and `PortalPageController` SHALL each have a PHPUnit test file asserting on their real HTTP response (status code, JSON body shape, or response headers), covering both the success path and the documented error paths (401 unauthenticated, 403 unauthorised). A passing unit test on a service class (e.g. `PortalObjectReaderTest`) SHALL NOT be treated as coverage for the controller that calls it.

#### Scenario: IDOR guard is regression-tested
- **GIVEN** a subject entitled only to `(register: supplierTender, schema:
  supplierTender)`
- **WHEN** an automated test calls
  `ContributionController::collection('otherRegister', 'otherSchema')` with
  that subject
- **THEN** the test asserts a 403 response — not merely that a human once
  observed this manually (tasks.md T05's live probe)

#### Scenario: Ownership stamping is regression-tested
- **GIVEN** a `create` request body containing a client-supplied `subjectRef`
  field that does not match the authenticated subject
- **WHEN** an automated test calls `ContributionController::create()`
- **THEN** the test asserts the object is written with the server-derived
  subject, never the client-supplied value — not merely that a human once
  probed this with a "smuggled" field (tasks.md T06's live probe)

#### Scenario: Debug-gated dev-login is regression-tested both ways
- **GIVEN** the dev-login debug flag is enabled, then disabled
- **WHEN** an automated test calls `SessionController`'s dev-login endpoint in
  each state
- **THEN** it asserts a minted token in the enabled case and the gated
  (404/disabled) response in the disabled case — not only "works when I tried
  it once"

### Requirement: The supplier login → data → action flow MUST have a Playwright e2e test

An automated Playwright spec SHALL drive the real portal page through dev-login, a contribution read, a forbidden-collection 403, and a create action, per the supplier-portal spec's own T13 note ("@e2e T13 covers login → collections → action → notification").

#### Scenario: End-to-end supplier flow is automated
- **GIVEN** a running Portaliq instance with the demo contribution
- **WHEN** the Playwright spec runs
- **THEN** it drives dev-login, lists contributions, reads an entitled
  collection, is refused a non-entitled one, and performs a create action —
  all through the real HTTP router, not direct service calls

## Notes

- **@e2e** covers both new requirements directly (tasks.md 4.1-4.4).

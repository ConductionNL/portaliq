---
status: proposed
---

# Spec: portal-contribution-contract

## Purpose

Delta from `case-actions-sign-a-document`: a row action may forward to an
endpoint, and an action's resolved scope claim may ride inside the signed
assertion.

## MODIFIED Requirements

### Requirement: Frozen assertion wire format

The A6 `X-Portal-Subject` assertion wire format MUST be treated as frozen
for receiver-side verifiers: header exactly `{"alg": "HS256", "typ": "JWT"}`
and the claim set `sub`, `audience`, `organisation`, `trust`, `jti`, `use`
(literal `"assertion"`), `iat`, `exp`, `iss` (literal `"portaliq"`), with
`exp - iat` equal to the 60-second assertion TTL. When the forwarded action
declares a `scopeClaim`, the assertion MUST carry exactly one additional
claim, named after that claim and holding the value resolved server-side
from the subject's portal account; it MUST NOT carry any other claim. A unit
test MUST pin every element of that shape so any drift fails loudly before it
can break domain-app verifiers templated against it.

#### Scenario: The assertion shape is pinned

- GIVEN a freshly minted `X-Portal-Subject` assertion for an action with no `scopeClaim`
- WHEN its header and claims are decoded
- THEN the header is exactly `{"alg": "HS256", "typ": "JWT"}` and the claim keys are exactly `sub`, `audience`, `organisation`, `trust`, `jti`, `use`, `iat`, `exp`, `iss` with `use = "assertion"`, `iss = "portaliq"`, and `exp - iat = 60`
- @e2e exclude wire-format pin, a PHPUnit compatibility test by definition; no UI or HTTP surface

#### Scenario: A declared scope claim adds exactly one claim

- GIVEN an action declaring `scopeClaim: "signerEmail"` and a subject whose account resolves it
- WHEN the assertion for that forward is decoded
- THEN the claim keys are the nine frozen keys plus `signerEmail`, and nothing else
- @e2e exclude wire-format pin; PHPUnit

### Requirement: Server-enforced status transitions

A `type: update` action MAY declare `set`, a map of WHITELISTED field to fixed
value the SERVER applies over the client body (after whitelisting, before the
write). A collection MAY declare `rowActions`: entries resolving by id to a
`type: update` action or to an endpoint action in the same contribution,
rendered as per-row buttons. An entry MAY be a string id or an object with an
`id`; only the id is read, and an endpoint row action MUST declare a
`rowField`. The `PATCH` update endpoint accepts `?action=<id>` to select which
update action to apply. The transition target is tamper-proof (the client can
never choose an arbitrary value); malformed `set`/`rowActions` are dropped
fail-closed; ownership re-verification and scope re-stamp still run.

#### Scenario: A transition target cannot be tampered with

- GIVEN an update action `close` with `fields: [status]` and `set: {status: closed}`
- WHEN the subject PATCHes their own row with `?action=close` and body `{status: "hacked"}`
- THEN the saved `status` is `closed` (server `set` overrides the client) and 200 is returned
- @e2e exclude API-level tamper assertion on a hand-crafted body; pinned by PortalManifestNormaliserTest::testSetKeepsOnlyWhitelistedScalarTransitionValues and the PATCH-path cases in ContributionControllerTest

#### Scenario: rowActions and set fail closed

- GIVEN `set: {status: closed, subjectRef: other}` and `rowActions: [close, createTicket, ghost]`
- WHEN normalised
- THEN `set` keeps only `{status: closed}` and `rowActions` keeps only `[close]`
- @e2e exclude Normaliser fail-closed contract on the manifest structure; pinned by PortalManifestNormaliserTest::testRowActionsResolveOnlyToUpdateActionsInContribution and ::testMalformedSetIsDropped

#### Scenario: An endpoint action resolves as a row action

- GIVEN an endpoint action `sign` with `rowField: signingRequestId` and `rowActions: [{id: sign}]`
- WHEN normalised
- THEN `rowActions` keeps `sign` with kind `endpoint`
- @e2e exclude Normaliser contract; pinned by PortalManifestNormaliserTest

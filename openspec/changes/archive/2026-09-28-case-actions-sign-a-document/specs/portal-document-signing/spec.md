---
status: proposed
---

# Spec: portal-document-signing

## Purpose

A resident signs a document the organisation prepared for them, inside the
portal, without printing it. The signature itself is filinq's; the portal
shows the document, takes the resident's confirmation and forwards the act
with an identity only the server decides. Requested by TenderNed 409958,
portaliq matrix row `dem-tnd-sign-in-portal`.

## ADDED Requirements

### Requirement: An endpoint action can be offered on a row (REQ-SGN-001)

The manifest normaliser SHALL keep a collection's `rowActions` entry when it
resolves by id to a `type: update` action or to an endpoint action of the
same contribution. An entry given as an object SHALL be reduced to its `id`,
and the endpoint, method and `minTrust` SHALL come only from the top-level
action. An endpoint row action without a declared `rowField` SHALL be
dropped. Every other entry SHALL be dropped, as today.

#### Scenario: Filinq's inline sign action survives normalisation
- **GIVEN** a contribution whose collection declares `rowActions: [{id: "sign", endpoint: "/elsewhere"}]` and a top-level endpoint action `sign` with endpoint `/apps/filinq/api/portal/signing/sign` and `rowField: "signingRequestId"`
- **WHEN** the manifest is normalised for a resident
- **THEN** the collection's row action `sign` resolves with kind `endpoint` and the endpoint `/apps/filinq/api/portal/signing/sign`
- @e2e exclude Normaliser contract on a data structure; pinned by PortalManifestNormaliserTest

#### Scenario: An endpoint row action with no row field is dropped
- **GIVEN** a top-level endpoint action `archive` with no `rowField`, named in a collection's `rowActions`
- **WHEN** the manifest is normalised
- **THEN** the collection carries no `archive` row action
- @e2e exclude Normaliser contract; the browser-visible result is an absent button

### Requirement: A row-scoped forward acts only on a row the resident can read (REQ-SGN-002)

`POST /portal/api/collections/{register}/{schema}/{id}/actions/{actionId}`
SHALL forward an endpoint row action for one row. It SHALL return 401
without a portal session, 403 unless the named collection declares
`actionId` as an endpoint row action and every `minTrust` is met, and 404
when the scoped read of `{id}` under that collection returns nothing. It
SHALL stamp `{id}` into the forwarded body under the action's `rowField`,
over any client value, and SHALL make no outbound request on any refusal.

#### Scenario: A resident cannot sign another resident's document
- **GIVEN** a resident signed in with DigiD and a signing request that belongs to someone else
- **WHEN** the resident posts to the row-scoped forward for `sign` with that request's id
- **THEN** the response is 404 and no request reaches filinq
- @e2e exclude Refusal at the server seam; pinned by ContributionControllerTest with a stubbed forwarder that must not be called

#### Scenario: The row id in the body cannot be swapped
- **GIVEN** a resident's own signing request `A` and a body `{signingRequestId: "B", consent: true}`
- **WHEN** the resident posts to the row-scoped forward for `sign` on row `A`
- **THEN** the body filinq receives carries `signingRequestId: "A"`
- @e2e exclude Tamper assertion on a hand-crafted body; pinned by ContributionControllerTest

### Requirement: The resolved scope claim travels inside the signed assertion (REQ-SGN-003)

When a forwarded action declares a `scopeClaim`, the `X-Portal-Subject`
assertion SHALL carry the value portaliq resolved from the subject's own
portal account, as one extra claim named after the declared claim. A value
that does not resolve SHALL stop the forward with 403. A declared claim name
equal to a reserved claim SHALL be dropped at normalisation. An action with
no `scopeClaim` SHALL mint exactly the nine frozen claims.

#### Scenario: Filinq receives the signer claim it verifies
- **GIVEN** an endpoint action `sign` declaring `scopeClaim: "signerEmail"` and a resident whose portal account holds `claims.filinq.signerEmail`
- **WHEN** the resident signs through the portal
- **THEN** the assertion filinq verifies carries `signerEmail` with that value, and a body field of the same name is ignored
- @e2e exclude Server-to-server claim content; pinned by PortalJwtServiceTest and PortalActionForwarderTest

#### Scenario: No resolvable claim, no forward
- **GIVEN** an action declaring `scopeClaim: "signerEmail"` and a resident whose account holds no such claim
- **WHEN** the resident triggers the action
- **THEN** the response is 403 and nothing is forwarded
- @e2e exclude Refusal at the server seam; pinned by ContributionControllerTest

### Requirement: The resident reads the document before signing it (REQ-SGN-004)

The portal SHALL open a signing dialog from a row's `sign` action. It SHALL
fetch the document through the contribution's `viewDocument` action for that
row and show it with a download link. It SHALL keep the sign button disabled
until the resident ticks "I have read this document and I sign it." Without a
`viewDocument` action, it SHALL say the document cannot be shown and SHALL
offer no sign button.

#### Scenario: A resident signs an agreement
- **GIVEN** a resident signed in with DigiD with one document awaiting their signature
- **WHEN** they open "Documents awaiting my signature", choose "Sign", read the document, tick the confirmation and press "Sign"
- **THEN** the dialog closes, the row shows the new status, and the portal says "You signed {documentName}."
- e2e: `tests/e2e/case-actions-sign-a-document.spec.ts`

#### Scenario: The sign button waits for the confirmation
- **GIVEN** a resident in the signing dialog who has not ticked the confirmation
- **WHEN** they look at the sign button
- **THEN** it is disabled and nothing has been sent
- e2e: `tests/e2e/case-actions-sign-a-document.spec.ts`

### Requirement: A resident can decline with a reason (REQ-SGN-005)

The portal SHALL open a decline dialog from a row's `decline` action that
asks "Why do you decline?" and forwards the reason. On success it SHALL show
"You declined to sign {documentName}." and reload the collection. On a
refusal it SHALL show the refusal and keep the dialog open.

#### Scenario: A resident declines a mandate form
- **GIVEN** a resident with a mandate form awaiting their signature
- **WHEN** they choose "Decline to sign", type a reason and confirm
- **THEN** the portal says "You declined to sign {documentName}." and the row no longer offers "Sign"
- e2e: `tests/e2e/case-actions-sign-a-document.spec.ts`

# Tasks: portal-identity-space

## Schema and service

- [x] **T01**: Add `pending` to `portalAccount.status` as a declared lifecycle, plus `provisionedBy`, `provisionedAt`, `verifiedEmail`; make `identityRef` optional and route BSN through `BsnFormat` (REQ-PIS-001)
- [x] **T02**: Add `PortalAccountService::provision()` and the `portal.provision` action in the ADR-023 matrix (REQ-PIS-001)
- [x] **T03**: Insert the pending match into the callback's find-or-create, identity first, verified email second (REQ-PIS-002)

## Events

- [x] **T04**: Add `PortalAccountProvisionRequestedEvent` and `PortalAccountClaimRequestedEvent` with result slots and their listeners; `appId` from the dispatching context (REQ-PIS-003)

## Surfaces

- [x] **T05**: Add the "My cases" portal page over `kind: cases` collections and the `kind` hint in the contribution manifest (REQ-PIS-004)
- [ ] **T06**: Offer login on the token page when the subject has an account; add the staff "void pending account" action with a reason (REQ-PIS-004, D6)
  - The void action shipped: `PortalAccountService::voidPending()` plus `POST /api/accounts/void`, refused without a reason and refused on an account that is not pending. The login offer on the token page is left open: case sharing mints an openregister access link now, and the offer belongs on that reader's page rather than on a token page portaliq no longer owns.
  - RE-MEASURED 2026-09-18, AND IT IS NOT PORTALIQ'S TO BUILD. The access-link
    reader lives entirely in openregister (`AccessLinkReader`,
    `AccessLinkController::open`, `GET /api/public/links/{anchor}`, a
    `#[PublicPage]`), and portaliq references it NOWHERE:
    `grep -rln 'accessLink|access-link|AccessLink' lib/ src/` returns nothing.
    So the surface that should carry the offer belongs to the other app, which
    is what the note above already suspected.
  - 🔴 AND THE REQUIREMENT'S WORDING NEEDS A DECISION BEFORE ANYBODY BUILDS IT.
    "Offer login WHEN THE CASE'S SUBJECT HAS AN ACCOUNT" asks a public,
    unauthenticated page to reveal whether a named person holds an account, to
    whoever is holding the link. A link can be forwarded. That is account
    enumeration with extra steps, and it is the same shape as a login form that
    says whether an email is registered.
  - The change's own SCENARIO does not require it: it says only that "a login
    link is offered". An unconditional, generic offer satisfies the scenario and
    discloses nothing. Naming this rather than quietly building the conditional
    version, because the conditional version is the one somebody would write
    from the requirement prose alone.

## Quality

- [x] **T07**: PHPUnit: provision refusals, pending unreachable, identity and email matching, claim event, wrong-person isolation
- [x] **T08**: Playwright `tests/e2e/portal-identity-space.spec.ts`; Dutch and English strings; docs with screenshots; tell dossiq the two event names and the `linkedRequesterId` claim

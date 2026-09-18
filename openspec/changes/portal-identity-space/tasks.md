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

## Quality

- [x] **T07**: PHPUnit: provision refusals, pending unreachable, identity and email matching, claim event, wrong-person isolation
- [x] **T08**: Playwright `tests/e2e/portal-identity-space.spec.ts`; Dutch and English strings; docs with screenshots; tell dossiq the two event names and the `linkedRequesterId` claim

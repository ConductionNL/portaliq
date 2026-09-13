# Tasks: portal-identity-space

## Schema and service

- [ ] **T01**: Add `pending` to `portalAccount.status` as a declared lifecycle, plus `provisionedBy`, `provisionedAt`, `verifiedEmail`; make `identityRef` optional and route BSN through `BsnFormat` (REQ-PIS-001)
- [ ] **T02**: Add `PortalAccountService::provision()` and the `portal.provision` action in the ADR-023 matrix (REQ-PIS-001)
- [ ] **T03**: Insert the pending match into the callback's find-or-create, identity first, verified email second (REQ-PIS-002)

## Events

- [ ] **T04**: Add `PortalAccountProvisionRequestedEvent` and `PortalAccountClaimRequestedEvent` with result slots and their listeners; `appId` from the dispatching context (REQ-PIS-003)

## Surfaces

- [ ] **T05**: Add the "My cases" portal page over `kind: cases` collections and the `kind` hint in the contribution manifest (REQ-PIS-004)
- [ ] **T06**: Offer login on the token page when the subject has an account; add the staff "void pending account" action with a reason (REQ-PIS-004, D6)

## Quality

- [ ] **T07**: PHPUnit: provision refusals, pending unreachable, identity and email matching, claim event, wrong-person isolation
- [ ] **T08**: Playwright `tests/e2e/portal-identity-space.spec.ts`; Dutch and English strings; docs with screenshots; tell dossiq the two event names and the `linkedRequesterId` claim

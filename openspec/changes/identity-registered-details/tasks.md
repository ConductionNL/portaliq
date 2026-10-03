# Tasks: identity-registered-details

## The read

- [x] **T01**: `PortalRegisteredDetailsService`: choose person, company or none from the caller's own `portalAccount` (identityType plus a `BsnFormat` or 8-digit check) (REQ-IRD-001)
  - Verify: PHPUnit `PortalRegisteredDetailsServiceTest`, one case per identityType, a pseudonymous identityRef answers `no_registration_identifier`
- [x] **T02**: Call openregister's `BrpPersoonProvider` and `KvkProvider` in process, guarded by `class_exists()`, and map the answer to the fixed shape of design D3 (REQ-IRD-001, REQ-IRD-002)
  - Verify: PHPUnit with both providers mocked; assert the raw object and the BSN never appear in the result
- [x] **T03**: `GET /portal/api/identity/registered-details` on `PortalAccountSelfController`, bearer only, no client-supplied identifier; route in `appinfo/routes.php` beside the other identity routes (REQ-IRD-001)
  - Verify: PHPUnit controller test for 401 without a bearer; `hydra-gate-route-auth` and `hydra-gate-no-admin-idor` green
- [x] **T04**: Degrade to `source_unavailable` when a provider reports `unavailable`, log the cause without the BSN (REQ-IRD-003)
  - Verify: PHPUnit asserting the logger never receives the identityRef

## The screen

- [x] **T05**: A fixed "My details" nav entry and a `RegisteredDetails.jsx` section in `src/portal/components/`, person and company variants, with the empty and unavailable states (REQ-IRD-001, REQ-IRD-003)
  - Verify: Playwright `tests/e2e/identity-registered-details.spec.ts` with the lookup stubbed at the OpenConnector source
  - As built: `src/portal/components/RegisteredDetailsPage.jsx`, rendered by `tests/registered-details.spec.mjs` (8 tests); the e2e drives the endpoint against an instance with no source configured (unavailable, pseudonym, a supplied BSN ignored).
- [x] **T06**: `portal.registeredDetails.correctionFormBinding` and `addressInvestigationFormBinding` in the register; render each link only when its binding is set and published (REQ-IRD-004)
  - Verify: PHPUnit on the binding check; Playwright asserts no link when unset

## The count

- [ ] **T07**: Show `residentsAtAddress` when openregister answers a count by address object; otherwise the "not available" line (REQ-IRD-005)
  - Verify: PHPUnit for both branches; blocked on the openregister half named in the proposal
  - Portaliq half done: the service never fills the count (`testTheCountOfResidentsIsNotClaimedWhileOpenRegisterCannotAnswerIt`) and the section shows the not-available line, or a given count with no names (`tests/registered-details.spec.mjs`). The openregister ask is drafted for Ruben: `for-ruben/openregister-brp-residents-at-address-count.md`.

## Close

- [x] **T08**: Dutch and English strings in `src/portal/i18n/`; a docs page under `docs/` naming the two OpenConnector sources an administrator must configure; `openspec validate identity-registered-details --strict`

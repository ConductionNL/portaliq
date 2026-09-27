# Tasks: identity-registered-details

## The read

- [ ] **T01**: `PortalRegisteredDetailsService`: choose person, company or none from the caller's own `portalAccount` (identityType plus a `BsnFormat` or 8-digit check) (REQ-IRD-001)
  - Verify: PHPUnit `PortalRegisteredDetailsServiceTest`, one case per identityType, a pseudonymous identityRef answers `no_registration_identifier`
- [ ] **T02**: Call openregister's `BrpPersoonProvider` and `KvkProvider` in process, guarded by `class_exists()`, and map the answer to the fixed shape of design D3 (REQ-IRD-001, REQ-IRD-002)
  - Verify: PHPUnit with both providers mocked; assert the raw object and the BSN never appear in the result
- [ ] **T03**: `GET /portal/api/identity/registered-details` on `PortalAccountSelfController`, bearer only, no client-supplied identifier; route in `appinfo/routes.php` beside the other identity routes (REQ-IRD-001)
  - Verify: PHPUnit controller test for 401 without a bearer; `hydra-gate-route-auth` and `hydra-gate-no-admin-idor` green
- [ ] **T04**: Degrade to `source_unavailable` when a provider reports `unavailable`, log the cause without the BSN (REQ-IRD-003)
  - Verify: PHPUnit asserting the logger never receives the identityRef

## The screen

- [ ] **T05**: A fixed "My details" nav entry and a `RegisteredDetails.jsx` section in `src/portal/components/`, person and company variants, with the empty and unavailable states (REQ-IRD-001, REQ-IRD-003)
  - Verify: Playwright `tests/e2e/identity-registered-details.spec.ts` with the lookup stubbed at the OpenConnector source
- [ ] **T06**: `portal.registeredDetails.correctionFormBinding` and `addressInvestigationFormBinding` in the register; render each link only when its binding is set and published (REQ-IRD-004)
  - Verify: PHPUnit on the binding check; Playwright asserts no link when unset

## The count

- [ ] **T07**: Show `residentsAtAddress` when openregister answers a count by address object; otherwise the "not available" line (REQ-IRD-005)
  - Verify: PHPUnit for both branches; blocked on the openregister half named in the proposal

## Close

- [ ] **T08**: Dutch and English strings in `src/portal/i18n/`; a docs page under `docs/` naming the two OpenConnector sources an administrator must configure; `openspec validate identity-registered-details --strict`

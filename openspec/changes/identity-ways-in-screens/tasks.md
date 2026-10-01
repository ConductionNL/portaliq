# Tasks: identity-ways-in-screens

## Mails

- [x] **T01**: `reference-link`, `invitation` and `registration-activation` templates in `PortalIdentityMailer`; `requestReferenceLink()` and `register()` mail the secret instead of dropping it (REQ-IWI-001)
  - Verify: PHPUnit with `IMailer` mocked; the secret appears only in the mailed link, never in a log line or an answer
  - Done (server half PR): `PortalIdentityMailerTest`, `PortalIdentityControllerTest`.

## Registration

- [ ] **T02**: "Create an account" form on the site's sign-in screen (`src/site/`): challenge solved in the browser, honeypot, the policy's outcome shown (REQ-IWI-002)
  - Verify: Playwright `tests/e2e/identity-ways-in-screens.spec.ts`, registration under `approval` and under `activation`
- [ ] **T03**: `PortalAccountService::activate(token)` and consuming `#activate=` (REQ-IWI-002)
  - Verify: PHPUnit for a used and an expired token; Playwright follows the mailed link
  - Server half done: `PortalAccountActivationService::activate(token)` and `POST /portal/api/identity/activate` (`PortalAccountActivationServiceTest`: used, expired, staff-provisioned, Opis against `portalAccount`; `PortalIdentityControllerTest`). Open: the site consuming `#activate=`.

## Reference link

- [ ] **T04**: "Follow a case with its case number" form on the site (`src/site/`) listing the case types that admit `reference` (REQ-IWI-003)
  - Verify: Playwright requests a link and follows it from the captured mail
- [x] **T05**: The reference session of design D2 and the read path that honours it; every write route refuses it (REQ-IWI-003)
  - Verify: PHPUnit: a reference session reads its one row, a second row 404s, an amend answers 403; `hydra-gate-no-admin-idor` green
  - Done by #822: `PortalReferenceCaseServiceTest`, `PortalIdentityControllerTest::testAReferenceSessionReadsItsCaseReadOnly`, `PortalAuthMiddlewareTest::testAReferenceSessionIsRefusedOnEveryProtectedRoute`.

## Invitation

- [ ] **T06**: Consume `#invitation=` in the site (`src/site/`), show the acceptance screen, accept, then point to the e-mail sign-in (REQ-IWI-004)
  - Verify: Playwright accepts a mailed invitation; a second follow reads "This invitation is no longer valid."

## Doors only where they lead

- [ ] **T07**: `waysIn` in the site config (`templates/site.php`) and the site's sign-in screen rendering only the doors that are on (REQ-IWI-005)
  - Verify: PHPUnit on `PortalRuntimeConfigResolver` for a portal with and without an e-mail provider
  - Server half done: `PortalWaysInResolver` decides `waysIn` in the runtime config (`PortalWaysInResolverTest`, `PortalRuntimeConfigResolverTest`). Open: the site's sign-in screen rendering the doors.

## Close

- [ ] **T08**: Dutch and English strings; an administrator docs page on the registration policy and the e-mail provider; `openspec validate identity-ways-in-screens --strict`

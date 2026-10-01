# Tasks: identity-ways-in-screens

## Mails

- [x] **T01**: `reference-link`, `invitation` and `registration-activation` templates in `PortalIdentityMailer`; `requestReferenceLink()` and `register()` mail the secret instead of dropping it (REQ-IWI-001)
  - Verify: PHPUnit with `IMailer` mocked; the secret appears only in the mailed link, never in a log line or an answer

## Registration

- [x] **T02**: "Create an account" form on the sign-in screen: challenge solved in the browser, honeypot, the policy's outcome shown (REQ-IWI-002)
  - Verify: Playwright `tests/e2e/identity-ways-in-screens.spec.ts` (written, not run: needs the three e2e portals its header names); `tests/ways-in-screens.spec.mjs` runs in check:specs
- [x] **T03**: `PortalAccountService::activate(token)` and consuming `#activate=` (REQ-IWI-002)
  - Verify: PHPUnit `PortalAccountActivationServiceTest` (used, expired, staff-provisioned, Opis against `portalAccount`), `PortalIdentityControllerTest`; Playwright follows the link (route-answered)

## Reference link

- [x] **T04**: "Follow a case with its case number" form listing the case types that admit `reference` (REQ-IWI-003)
  - Verify: Playwright requests a link and follows it from the captured mail
- [x] **T05**: The reference session of design D2 and the read path that honours it; every write route refuses it (REQ-IWI-003)
  - Verify: built by #822; `PortalReferenceCaseServiceTest`, `PortalIdentityControllerTest::testAReferenceSessionReadsItsCaseReadOnly`, `PortalAuthMiddlewareTest::testAReferenceSessionIsRefusedOnEveryProtectedRoute`

## Invitation

- [x] **T06**: Consume `#invitation=`, show the acceptance screen, accept, then point to the e-mail sign-in (REQ-IWI-004)
  - Verify: Playwright accepts a mailed invitation; a second follow reads "This invitation is no longer valid."

## Doors only where they lead

- [x] **T07**: `waysIn` in the runtime config and the sign-in screen rendering only the doors that are on (REQ-IWI-005)
  - Verify: PHPUnit on `PortalRuntimeConfigResolver` for a portal with and without an e-mail provider

## Close

- [x] **T08**: Dutch and English strings; an administrator docs page on the registration policy and the e-mail provider; `openspec validate identity-ways-in-screens --strict`

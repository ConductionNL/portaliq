# Tasks: identity-ways-in-screens

## Mails

- [x] **T01**: `reference-link`, `invitation` and `registration-activation` templates in `PortalIdentityMailer`; `requestReferenceLink()` and `register()` mail the secret instead of dropping it (REQ-IWI-001)
  - Verify: PHPUnit with `IMailer` mocked; the secret appears only in the mailed link, never in a log line or an answer
  - Done (server half PR): `PortalIdentityMailerTest`, `PortalIdentityControllerTest`.

## Registration

- [x] **T02**: "Create an account" form on the site's sign-in screen (`src/site/`): challenge solved in the browser, honeypot, the policy's outcome shown (REQ-IWI-002)
  - Verify: Playwright `tests/e2e/identity-ways-in-screens.spec.ts`, registration under `approval` and under `activation`
  - Done: `src/site/components/WaysIn.vue` on `AccountArea.vue`'s signed-out screen, `solveChallenge()` and `waysInApi()` in `src/site/lib/waysIn.js`; `tests/ways-in-screens.spec.mjs` (`check:ways-in-screens` in `check:specs`); Playwright `tests/e2e/identity-ways-in-screens.spec.ts` written, not run (needs the three e2e portals its header names).
- [x] **T03**: `PortalAccountService::activate(token)` and consuming `#activate=` (REQ-IWI-002)
  - Verify: PHPUnit for a used and an expired token; Playwright follows the mailed link
  - Server half done: `PortalAccountActivationService::activate(token)` and `POST /portal/api/identity/activate` (`PortalAccountActivationServiceTest`: used, expired, staff-provisioned, Opis against `portalAccount`; `PortalIdentityControllerTest`). Open: the site consuming `#activate=`.
  - Site half done: `src/site/components/WayInLink.vue` consumes `#activate=` (mounted by `App.vue` before any page); the activation mail links to `/site` (`PortalDeepLinkBuilder::forSite()`, `PortalIdentityMailerTest::testTheWaysInOpenOnTheSite`).

## Reference link

- [x] **T04**: "Follow a case with its case number" form on the site (`src/site/`) listing the case types that admit `reference` (REQ-IWI-003)
  - Verify: Playwright requests a link and follows it from the captured mail
  - Done: the reference form in `WaysIn.vue` (a choice only when there are several case types); `WayInLink.vue` redeems `#reference=` and shows the one case read only.
- [x] **T05**: The reference session of design D2 and the read path that honours it; every write route refuses it (REQ-IWI-003)
  - Verify: PHPUnit: a reference session reads its one row, a second row 404s, an amend answers 403; `hydra-gate-no-admin-idor` green
  - Done by #822: `PortalReferenceCaseServiceTest`, `PortalIdentityControllerTest::testAReferenceSessionReadsItsCaseReadOnly`, `PortalAuthMiddlewareTest::testAReferenceSessionIsRefusedOnEveryProtectedRoute`.

## Invitation

- [x] **T06**: Consume `#invitation=` in the site (`src/site/`), show the acceptance screen, accept, then point to the e-mail sign-in (REQ-IWI-004)
  - Verify: Playwright accepts a mailed invitation; a second follow reads "This invitation is no longer valid."
  - Done: `WayInLink.vue` (the invitation heading, Accept, then the e-mail sign-in sentence; a second follow reads the refusal).

## Doors only where they lead

- [x] **T07**: `waysIn` in the site config (`templates/site.php`) and the site's sign-in screen rendering only the doors that are on (REQ-IWI-005)
  - Verify: PHPUnit on `PortalRuntimeConfigResolver` for a portal with and without an e-mail provider
  - Server half done: `PortalWaysInResolver` decides `waysIn` in the runtime config (`PortalWaysInResolverTest`, `PortalRuntimeConfigResolverTest`). Open: the site's sign-in screen rendering the doors.
  - Site half done: `PortalPageController::siteSignin()` passes `waysIn` (`PortalPageControllerTest::testSiteCarriesTheWaysIn`); `waysInFrom()` closes every door the config does not open; `AccountArea.vue` renders `WaysIn` only when one is open.

## Close

- [x] **T08**: Dutch and English strings; an administrator docs page on the registration policy and the e-mail provider; `openspec validate identity-ways-in-screens --strict`
  - Done: the strings in `src/site/lib/waysInStrings.js` (nl, en, no em-dashes; the site translator first, `waysInTranslator()`), checked by the node test; docs section "What visitors see on the sign-in screen" in `docs/operations/staff-accounts-and-registration.md`.

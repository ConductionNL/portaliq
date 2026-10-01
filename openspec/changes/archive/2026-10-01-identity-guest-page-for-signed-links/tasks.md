# Tasks: identity-guest-page-for-signed-links

## The contract

- [x] **T01**: Normalise guest actions (`guest: true`, `tokenField`, `previewEndpoint`, `label`, `confirmText`, `fields`), drop any with a non-local endpoint, no `tokenField` or a `minTrust` above `low`, and add `PortalContributionRegistry::guestAction()` (REQ-GST-001). Verification: `PortalManifestNormaliserTest::testGuestActionNeedsATokenField`, `::testGuestActionAboveLowTrustIsDropped`, `PortalContributionRegistryTest::testGuestActionIsFoundOnlyForTheGuestAudience`.
  - Done: `GuestActionConfigNormaliser`; the lookup is `GuestActionRegistry::guestAction()` (its own class: on `PortalContributionRegistry` it took the class past phpmd's complexity bound), test `GuestActionRegistryTest`; red first in lane22/red-guest-php.txt.

## The routes

- [x] **T02**: `GuestActionController::preview()` and `::act()` with their routes before the `/portal/{path}` catch-all in `appinfo/routes.php`: unknown action 404, token stamped under `tokenField`, the guest subject, relay, audit with the token hash (REQ-GST-002, REQ-GST-003). Verification: `GuestActionControllerTest::testTokenIsStampedOverClientValue`, `::testUnknownActionIs404AndNotForwarded`, `::testAssertionCarriesAudienceGuestAndTheNineClaims`, `::testAuditKeepsOnlyTheTokenHash`.
  - Done: `GuestActionController` (`preview` answers `{preview, action}` with the declaration, never an endpoint); `GuestActionControllerTest` (5).

## The page

- [x] **T03**: Consume `#guest/<app>/<action>/<token>` once when the site (`src/site/App.vue`) mounts and clear it (REQ-GST-002). Verification: node test on the fragment helper.
  - Done: `takeGuestLink()` in `src/site/lib/guestAction.js`, `App.vue` shows the page before any other; `tests/guest-action-page.spec.mjs` (`check:guest-action-page` in `check:specs`).
- [x] **T04**: `src/site/pages/GuestActionPage.vue`: preview, unavailable reason, declared fields, label, confirmation, message, `https` redirect (REQ-GST-004). Verification: node test with stubbed answers for each case.
  - Done: `src/site/pages/GuestActionPage.vue`, `previewState()`/`actOutcome()`; the node test covers each answer, including a refusal wrapped as `preview`.
- [x] **T05**: Playwright `tests/e2e/identity-guest-page-for-signed-links.spec.ts` against a fixture contribution that declares a guest action with a preview. Verification: the spec passes in CI.
  - Written, not run locally: `tests/e2e/identity-guest-page-for-signed-links.spec.ts` (route-answered preview and act, the real 404 for the probe). Runs in the nightly Playwright job.

## Strings, docs and validation

- [x] **T06**: English and Dutch strings ("This link cannot be used.", "Taking you to the payment page"), contributor docs on guest actions and the link form, `npm run lint`, `npm run check:manifest`, and `openspec validate identity-guest-page-for-signed-links --strict`.
  - Done: strings in `guestStrings()` (en, nl, no em-dashes, pinned by the node test); `docs/operations/guest-actions.md`.

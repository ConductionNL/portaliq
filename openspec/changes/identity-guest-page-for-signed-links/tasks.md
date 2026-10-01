# Tasks: identity-guest-page-for-signed-links

## The contract

- [ ] **T01**: Normalise guest actions (`guest: true`, `tokenField`, `previewEndpoint`, `label`, `confirmText`, `fields`), drop any with a non-local endpoint, no `tokenField` or a `minTrust` above `low`, and add `PortalContributionRegistry::guestAction()` (REQ-GST-001). Verification: `PortalManifestNormaliserTest::testGuestActionNeedsATokenField`, `::testGuestActionAboveLowTrustIsDropped`, `PortalContributionRegistryTest::testGuestActionIsFoundOnlyForTheGuestAudience`.

## The routes

- [ ] **T02**: `GuestActionController::preview()` and `::act()` with their routes before the `/portal/{path}` catch-all in `appinfo/routes.php`: unknown action 404, token stamped under `tokenField`, the guest subject, relay, audit with the token hash (REQ-GST-002, REQ-GST-003). Verification: `GuestActionControllerTest::testTokenIsStampedOverClientValue`, `::testUnknownActionIs404AndNotForwarded`, `::testAssertionCarriesAudienceGuestAndTheNineClaims`, `::testAuditKeepsOnlyTheTokenHash`.

## The page

- [ ] **T03**: Consume `#guest/<app>/<action>/<token>` once when the site (`src/site/App.vue`) mounts and clear it (REQ-GST-002). Verification: node test on the fragment helper.
- [ ] **T04**: `src/site/pages/GuestActionPage.vue`: preview, unavailable reason, declared fields, label, confirmation, message, `https` redirect (REQ-GST-004). Verification: node test with stubbed answers for each case.
- [ ] **T05**: Playwright `tests/e2e/identity-guest-page-for-signed-links.spec.ts` against a fixture contribution that declares a guest action with a preview. Verification: the spec passes in CI.

## Strings, docs and validation

- [ ] **T06**: English and Dutch strings ("This link cannot be used.", "Taking you to the payment page"), contributor docs on guest actions and the link form, `npm run lint`, `npm run check:manifest`, and `openspec validate identity-guest-page-for-signed-links --strict`.

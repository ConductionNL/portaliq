# Tasks: signin-session-idle-warning-and-sso

## The idle window

- [x] **T01**: Add the `session_idle_timeout` app setting (default 900, clamped 300 to 3600) and pass it as the TTL in `PortalSessionService::mintSession()` (REQ-SIS-001)
  - PHPUnit `PortalSessionServiceTest::testBearerLivesOneIdleWindow`, `::testIdleTimeoutIsClamped`, `::testRefreshPastTheCapIsStillRefused`
- [x] **T02**: Return `expiresAt`, `hardExpiresAt` and `idleTimeout` from `GET /portal/api/session` and `POST /portal/api/session/refresh` (REQ-SIS-001)
  - PHPUnit `SessionControllerTest::testIndexReportsExpiry` and `::testRefreshReportsExpiry`

## The screens

- [x] **T03**: Portal SPA: replace the fixed 25 minute interval with an activity-driven refresh, and resync across tabs on the `storage` event (REQ-SIS-002)
  - Playwright `tests/e2e/signin-session-idle-warning.spec.ts`: with a 300 second window and a fake clock, typing keeps the session, idling does not
- [x] **T04**: `src/portal/components/IdleWarningDialog.jsx` with the countdown, "Stay signed in" and "Sign out", focus on open and a polite live region (REQ-SIS-003)
  - Playwright `tests/e2e/signin-session-idle-warning.spec.ts`: the dialog opens two minutes before expiry and "Stay signed in" extends it
- [x] **T05**: The login screen message after an inactivity sign-out, and the cap variant of the dialog without "Stay signed in" (REQ-SIS-003, REQ-SIS-004)
  - Playwright `tests/e2e/signin-session-idle-warning.spec.ts`: after expiry the login screen names inactivity; near the cap the dialog offers only sign-in again
- [x] **T06**: Site renderer: activity-driven refresh and `src/site/components/IdleWarningDialog.vue` (REQ-SIS-002, REQ-SIS-003)
  - Playwright `tests/e2e/signin-session-idle-warning.spec.ts`, site renderer project

## Single sign-on

- [x] **T07**: `silent=1` on the OIDC start adds `prompt=none` and records `silent` on the `portalOidcState` row; add the optional `silent` property to the schema (REQ-SIS-005)
  - PHPUnit `OidcClientServiceTest::testSilentAuthorizationUrlCarriesPromptNone`, `SessionControllerTest::testSilentStartRecordsTheFlag`
- [x] **T08**: A silent row that comes back with `login_required`, `interaction_required`, `consent_required` or `account_selection_required` lands on the login screen with no message; every other error keeps the generic failure (REQ-SIS-005)
  - PHPUnit `SessionControllerTest::testSilentLoginRequiredLandsQuietly` and `::testNonSilentErrorKeepsTheGenericFailure`
- [x] **T09**: The `silentSignIn` organisation setting, tried once per browser session by the portal SPA (REQ-SIS-005)
  - Playwright `tests/e2e/signin-session-sso.spec.ts`: with a stub broker holding a session, opening the portal signs the resident in without a click; without one, the login screen shows and no second attempt is made
- [x] **T10**: Keep `end_session_endpoint` from discovery, add the `provider` claim to the bearer, and return `logoutUrl` from `DELETE /portal/api/session` for an OIDC-minted session (REQ-SIS-006)
  - PHPUnit `SessionControllerTest::testLogoutReturnsTheBrokerLogoutUrl`, `::testLogoutWithoutEndSessionReturnsNone`, `::testDevLoginSessionHasNoProvider`
- [x] **T11**: Both SPAs follow `logoutUrl` after sign-out (REQ-SIS-006)
  - Playwright `tests/e2e/signin-session-sso.spec.ts`: signing out sends the browser to the stub broker's logout endpoint and back

## Docs and strings

- [x] **T12**: Dutch and English strings for the dialog, the cap variant and the login screen message; a docs page on the idle window, silent sign-in and what a third-party front-end must now do
- [x] **T13**: `openspec validate signin-session-idle-warning-and-sso --strict`

## As built (2026-09-30)

- The Playwright files `tests/e2e/signin-session-idle-warning.spec.ts` and `tests/e2e/signin-session-sso.spec.ts` are written, not run in this lane. The browser logic they drive is pinned by `tests/idle-session.spec.mjs` (check:idle-session): the warning lead, the activity rule, the cap, the inactivity message, silent sign-in once per browser session, the broker sign-out address, the strings in nl and en, and the wiring in both SPAs.
- T07: the register goes to 0.50.0 (portalOidcState 0.3.0, optional `silent`). 0.49.0 is taken by portal-in-place-editing A3 (#960). `OidcStateStoreServiceTest::testASilentRowRecordsTheFlag` validates the row against the real schema with Opis, undeclared properties refused.
- T09: `silentSignIn` in the presentation override names the provider to try; it counts only when the organisation offers that provider on its own OIDC broker (`PortalOrganisationConfigService::silentSignInProvider`), and the runtime config carries it from the organisation like `oidcProviders`.
- T10: `PortalJwtService::createSession()` takes the provider in its `$context` array (renamed from `$branch`, which it also carries), which keeps it under the phpmd parameter limit.
- T12: docs page `docs/operations/staying-signed-in-and-single-sign-on.md`. The site renderer reads the same strings from `src/portal/i18n/{nl,en}.json`.

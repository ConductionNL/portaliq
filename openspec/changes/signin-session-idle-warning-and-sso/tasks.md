# Tasks: signin-session-idle-warning-and-sso

## The idle window

- [ ] **T01**: Add the `session_idle_timeout` app setting (default 900, clamped 300 to 3600) and pass it as the TTL in `PortalSessionService::mintSession()` (REQ-SIS-001)
  - PHPUnit `PortalSessionServiceTest::testBearerLivesOneIdleWindow`, `::testIdleTimeoutIsClamped`, `::testRefreshPastTheCapIsStillRefused`
- [ ] **T02**: Return `expiresAt`, `hardExpiresAt` and `idleTimeout` from `GET /portal/api/session` and `POST /portal/api/session/refresh` (REQ-SIS-001)
  - PHPUnit `SessionControllerTest::testIndexReportsExpiry` and `::testRefreshReportsExpiry`

## The screens

- [ ] **T03**: Portal SPA: replace the fixed 25 minute interval with an activity-driven refresh, and resync across tabs on the `storage` event (REQ-SIS-002)
  - Playwright `tests/e2e/signin-session-idle-warning.spec.ts`: with a 300 second window and a fake clock, typing keeps the session, idling does not
- [ ] **T04**: `src/portal/components/IdleWarningDialog.jsx` with the countdown, "Stay signed in" and "Sign out", focus on open and a polite live region (REQ-SIS-003)
  - Playwright `tests/e2e/signin-session-idle-warning.spec.ts`: the dialog opens two minutes before expiry and "Stay signed in" extends it
- [ ] **T05**: The login screen message after an inactivity sign-out, and the cap variant of the dialog without "Stay signed in" (REQ-SIS-003, REQ-SIS-004)
  - Playwright `tests/e2e/signin-session-idle-warning.spec.ts`: after expiry the login screen names inactivity; near the cap the dialog offers only sign-in again
- [ ] **T06**: Site renderer: activity-driven refresh and `src/site/components/IdleWarningDialog.vue` (REQ-SIS-002, REQ-SIS-003)
  - Playwright `tests/e2e/signin-session-idle-warning.spec.ts`, site renderer project

## Single sign-on

- [ ] **T07**: `silent=1` on the OIDC start adds `prompt=none` and records `silent` on the `portalOidcState` row; add the optional `silent` property to the schema (REQ-SIS-005)
  - PHPUnit `OidcClientServiceTest::testSilentAuthorizationUrlCarriesPromptNone`, `SessionControllerTest::testSilentStartRecordsTheFlag`
- [ ] **T08**: A silent row that comes back with `login_required`, `interaction_required`, `consent_required` or `account_selection_required` lands on the login screen with no message; every other error keeps the generic failure (REQ-SIS-005)
  - PHPUnit `SessionControllerTest::testSilentLoginRequiredLandsQuietly` and `::testNonSilentErrorKeepsTheGenericFailure`
- [ ] **T09**: The `silentSignIn` organisation setting, tried once per browser session by the portal SPA (REQ-SIS-005)
  - Playwright `tests/e2e/signin-session-sso.spec.ts`: with a stub broker holding a session, opening the portal signs the resident in without a click; without one, the login screen shows and no second attempt is made
- [ ] **T10**: Keep `end_session_endpoint` from discovery, add the `provider` claim to the bearer, and return `logoutUrl` from `DELETE /portal/api/session` for an OIDC-minted session (REQ-SIS-006)
  - PHPUnit `SessionControllerTest::testLogoutReturnsTheBrokerLogoutUrl`, `::testLogoutWithoutEndSessionReturnsNone`, `::testDevLoginSessionHasNoProvider`
- [ ] **T11**: Both SPAs follow `logoutUrl` after sign-out (REQ-SIS-006)
  - Playwright `tests/e2e/signin-session-sso.spec.ts`: signing out sends the browser to the stub broker's logout endpoint and back

## Docs and strings

- [ ] **T12**: Dutch and English strings for the dialog, the cap variant and the login screen message; a docs page on the idle window, silent sign-in and what a third-party front-end must now do
- [ ] **T13**: `openspec validate signin-session-idle-warning-and-sso --strict`

# Tasks: signin-integriq-broker-login

## Configuration

- [x] **T01**: Add the `loginRoutes` map and the broker settings (start address, exchange address, consumer id) to the organisation's presentation override, and a sensitive `broker_secret_<organisationUuid>` setter on `PortalOrganisationConfigService` (REQ-BEL-001)
  - PHPUnit `PortalOrganisationConfigServiceTest::testBrokerRouteNeedsEveryField` and `::testBrokerSecretIsNeverInResolve`
- [x] **T02**: Return `route` on every entry of `resolve()`'s provider list, and list a provider only when its chosen route is fully configured (REQ-BEL-001)
  - PHPUnit `PortalOrganisationConfigServiceTest::testProviderListCarriesRoute` and `::testUnconfiguredBrokerRouteHidesTheProvider`

## The start

- [x] **T03**: Add `route` to `portalOidcState` in `lib/Settings/portaliq_register.json`, move `codeVerifier` out of `required`, and let `OidcStateStoreService::create()` write a broker row (REQ-BEL-002)
  - PHPUnit `OidcStateStoreServiceTest::testBrokerRowRecordsItsRoute`
- [x] **T04**: `SessionController::brokerStart()` on `GET /portal/api/session/broker/start`, accepting `org` or `portal`, refusing a provider not routed to the broker, and redirecting to integriq with organisation, provider, trust, consumer and relay state (REQ-BEL-002)
  - PHPUnit `SessionControllerBrokerTest::testStartRedirectsWithRelayState`, `::testStartResolvesOrganisationFromPortal`, `::testStartRefusesAnOidcRoutedProvider`

## The callback

- [x] **T05**: `SessionController::brokerCallback()` on `GET /portal/api/session/broker/callback`: consume the state, refuse a row of the other route, redeem the code at the exchange address with the consumer secret (REQ-BEL-003)
  - PHPUnit `SessionControllerBrokerTest::testCallbackRefusesAnOidcState`, `::testCallbackRefusesANon200Exchange`
- [x] **T06**: Check `use`, `iss`, `audience`, `organisation`, `provider` and `exp` on the envelope before acting on it (REQ-BEL-004)
  - PHPUnit `BrokerEnvelopeCheckTest`, one test per claim, each mutation reddening one assertion
- [x] **T07**: Map the envelope to `findOrCreate()` and `issueSession()` and redirect with the bearer in the fragment (REQ-BEL-005)
  - PHPUnit `SessionControllerBrokerTest::testEnvelopeMintsASessionWithItsTrust` and `::testUnknownTrustBecomesLow`
- [x] **T08**: On any failure redirect to the portal with `#signin=failed` and no reason (REQ-BEL-006)
  - PHPUnit `SessionControllerBrokerTest::testEveryFailureLandsOnTheSameFragment`

## The screens

- [x] **T09**: `portalApi.loginStartUrl(provider, route)`; `App.jsx` starts the login by `route`; `src/site/lib/authApi.js` `signInRoutes()` does the same (REQ-BEL-001)
  - Playwright `tests/e2e/signin-integriq-broker-login.spec.ts`: with a stubbed integriq start and exchange, the DigiD button leads through the broker route and lands signed in
- [x] **T10**: Read and strip `#signin=failed` and show the one failure message on the login screen of both SPAs (REQ-BEL-006)
  - Playwright `tests/e2e/signin-integriq-broker-login.spec.ts`: a refused exchange lands on the login screen with the message
- [x] **T11**: Admin settings: the route choice per provider, the broker fields, and the warning that accounts do not carry over between routes (REQ-BEL-001)
  - Manual check: switch DigiD to `broker` for a test organisation without a start address and confirm the settings screen refuses it

## Docs and strings

- [x] **T12**: Dutch and English strings for the failure message and the admin warning; a docs page on configuring the broker route and what integriq must provide first
- [x] **T13**: `openspec validate signin-integriq-broker-login --strict`

## Built 2026-09-29 (lane 10), where the code differs from the text above

- T04, T05: the two endpoints live on their own `lib/Controller/BrokerSessionController.php` (`brokerSession#start`, `brokerSession#callback`), because `SessionController` sits at the size bound; the logic is `lib/Service/Signin/BrokerLogin.php` over `BrokerExchangeClient` and `BrokerEnvelopeCheck`. Tests: `BrokerLoginTest` (the start and callback cases named above), `BrokerSessionControllerTest`, `BrokerEnvelopeCheckTest`.
- T09: `SessionController::oidcStart()` forwards a broker-routed provider to the broker start, so the public site's sign-in links (`signInRoutes()`, which name modes, not routes) reach the route the organisation chose without the site knowing it. The portal SPA picks the start by `route` (`src/portal/lib/signinRoute.js`). Proved by `tests/broker-login.spec.mjs` and `SessionControllerTest::testOidcStartForwardsABrokerRoutedProvider`; the Playwright spec is written by T13's successor, not run here (no integriq start endpoint exists yet).
- T10: `#signin=failed` is read and stripped in both SPAs (`consumeSigninFailed`, `takeSigninFailed`), `tests/broker-login.spec.mjs`, `tests/site-auth.spec.mjs`.
- T11: no admin screen for sign-in existed at all (the OIDC client secret setter had no caller), so the settings are a **Sign-in** widget on the portal's page (`src/widgets/PortalSignin.vue`, `PortalSigninController` GET/PUT `/api/portals/{slug}/signin`, admin-only, `PortalSigninSettings`). The manual check is automated: `PortalSigninSettingsTest::testABrokerRouteWithoutAStartAddressIsRefused`.
- T12: `docs/operations/signing-in-through-integriq.md`; strings in `l10n/` and `src/portal/i18n/`.
- The start parameters integriq's start address must accept (`organisation`, `provider`, `trust`, `consumer`, `state`, `returnUrl`) are portaliq's proposal: integriq's `idp-broker-envelope-runtime` has no start endpoint yet.

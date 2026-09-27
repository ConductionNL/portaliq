# Tasks: signin-integriq-broker-login

## Configuration

- [ ] **T01**: Add the `loginRoutes` map and the broker settings (start address, exchange address, consumer id) to the organisation's presentation override, and a sensitive `broker_secret_<organisationUuid>` setter on `PortalOrganisationConfigService` (REQ-BEL-001)
  - PHPUnit `PortalOrganisationConfigServiceTest::testBrokerRouteNeedsEveryField` and `::testBrokerSecretIsNeverInResolve`
- [ ] **T02**: Return `route` on every entry of `resolve()`'s provider list, and list a provider only when its chosen route is fully configured (REQ-BEL-001)
  - PHPUnit `PortalOrganisationConfigServiceTest::testProviderListCarriesRoute` and `::testUnconfiguredBrokerRouteHidesTheProvider`

## The start

- [ ] **T03**: Add `route` to `portalOidcState` in `lib/Settings/portaliq_register.json`, move `codeVerifier` out of `required`, and let `OidcStateStoreService::create()` write a broker row (REQ-BEL-002)
  - PHPUnit `OidcStateStoreServiceTest::testBrokerRowRecordsItsRoute`
- [ ] **T04**: `SessionController::brokerStart()` on `GET /portal/api/session/broker/start`, accepting `org` or `portal`, refusing a provider not routed to the broker, and redirecting to integriq with organisation, provider, trust, consumer and relay state (REQ-BEL-002)
  - PHPUnit `SessionControllerBrokerTest::testStartRedirectsWithRelayState`, `::testStartResolvesOrganisationFromPortal`, `::testStartRefusesAnOidcRoutedProvider`

## The callback

- [ ] **T05**: `SessionController::brokerCallback()` on `GET /portal/api/session/broker/callback`: consume the state, refuse a row of the other route, redeem the code at the exchange address with the consumer secret (REQ-BEL-003)
  - PHPUnit `SessionControllerBrokerTest::testCallbackRefusesAnOidcState`, `::testCallbackRefusesANon200Exchange`
- [ ] **T06**: Check `use`, `iss`, `audience`, `organisation`, `provider` and `exp` on the envelope before acting on it (REQ-BEL-004)
  - PHPUnit `BrokerEnvelopeCheckTest`, one test per claim, each mutation reddening one assertion
- [ ] **T07**: Map the envelope to `findOrCreate()` and `issueSession()` and redirect with the bearer in the fragment (REQ-BEL-005)
  - PHPUnit `SessionControllerBrokerTest::testEnvelopeMintsASessionWithItsTrust` and `::testUnknownTrustBecomesLow`
- [ ] **T08**: On any failure redirect to the portal with `#signin=failed` and no reason (REQ-BEL-006)
  - PHPUnit `SessionControllerBrokerTest::testEveryFailureLandsOnTheSameFragment`

## The screens

- [ ] **T09**: `portalApi.loginStartUrl(provider, route)`; `App.jsx` starts the login by `route`; `src/site/lib/authApi.js` `signInRoutes()` does the same (REQ-BEL-001)
  - Playwright `tests/e2e/signin-integriq-broker-login.spec.ts`: with a stubbed integriq start and exchange, the DigiD button leads through the broker route and lands signed in
- [ ] **T10**: Read and strip `#signin=failed` and show the one failure message on the login screen of both SPAs (REQ-BEL-006)
  - Playwright `tests/e2e/signin-integriq-broker-login.spec.ts`: a refused exchange lands on the login screen with the message
- [ ] **T11**: Admin settings: the route choice per provider, the broker fields, and the warning that accounts do not carry over between routes (REQ-BEL-001)
  - Manual check: switch DigiD to `broker` for a test organisation without a start address and confirm the settings screen refuses it

## Docs and strings

- [ ] **T12**: Dutch and English strings for the failure message and the admin warning; a docs page on configuring the broker route and what integriq must provide first
- [ ] **T13**: `openspec validate signin-integriq-broker-login --strict`

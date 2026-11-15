# Tasks: portal-broker-login-keeps-the-portal

- [x] **T1**: The broker start resolves the portal once; the login returns to `?portal=<slug>` of the resolved portal, URL-encoded, and to the plain portal address for an unknown one
  - PHPUnit `BrokerSessionControllerTest::testALoginStartedFromAPortalReturnsToIt`, `::testStartResolvesOrganisationFromPortal`
- [x] **T2**: The OIDC start forwards the resolved portal's slug when it hands a broker-routed provider to the broker start
  - PHPUnit `SessionControllerTest::testOidcStartForwardsABrokerRoutedProvider`
- [x] **T3**: A failed start or callback lands on the portal the login started from, with the same failure fragment; a stored address that is not a local path is not followed
  - PHPUnit `BrokerSessionControllerTest::testAFailedLoginLandsOnThePortalItStartedFrom`; `BrokerLoginTest::testCallbackRefusesANon200Exchange`, `::testAnEnvelopeForAnotherOrganisationIsRefused`
- [x] **T4**: The relay state travels as `relayState`, the name integriq reads and returns; the callback falls back to `state`
  - PHPUnit `BrokerLoginTest::testStartRedirectsWithRelayState`, `BrokerSessionControllerTest::testTheCallbackReadsIntegriqsRelayState`

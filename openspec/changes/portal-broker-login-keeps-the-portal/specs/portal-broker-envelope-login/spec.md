## ADDED Requirements

### Requirement: A broker login returns to the portal it started from (REQ-BEL-007)

The broker start SHALL resolve the `portal` it is given once. When `org` is empty the organisation SHALL be the resolved portal's `organisation`. The return address stored in the state row SHALL be the site page the login started on, when that page is on the site route, else the portal address with `?portal=<slug>` of the resolved portal, URL-encoded. An unknown portal SHALL return to the plain portal address. Only a resolved portal's slug SHALL be echoed, never the raw input. The OIDC start SHALL forward the resolved portal's slug when it hands a provider to the broker start.

#### Scenario: A parent signs in through the broker on the school portal
- **GIVEN** a portal `wilgenboom` whose organisation routes DigiD to integriq
- **WHEN** a parent starts DigiD on `/apps/portaliq/portal?portal=wilgenboom` and the login completes
- **THEN** she lands on `/apps/portaliq/portal?portal=wilgenboom` with the bearer in the fragment, and the header shows the portal's title
- @e2e exclude integriq's vendor half is not built, so no broker round trip runs locally; covered by PHPUnit `BrokerSessionControllerTest::testALoginStartedFromAPortalReturnsToIt` and `SessionControllerTest::testOidcStartForwardsABrokerRoutedProvider`

#### Scenario: An unknown portal returns to the plain portal
- **GIVEN** no portal with slug `no-such-portal`
- **WHEN** a broker login starts with `portal=no-such-portal`
- **THEN** the stored return address is the plain portal address
- @e2e exclude covered by PHPUnit `BrokerSessionControllerTest::testALoginStartedFromAPortalReturnsToIt`

### Requirement: A failed broker login shows its message on the portal it started from (REQ-BEL-008)

A failed broker start SHALL land on the resolved portal's address with `#signin=failed`. A failure after the state row is spent SHALL land on the return address stored in that row with `#signin=failed`, when that address is a path on this server, else on the plain portal address. The fragment and the message SHALL stay the same for every cause (REQ-BEL-006).

#### Scenario: A refused exchange shows the message on the school portal
- **GIVEN** a broker login started on `/apps/portaliq/portal?portal=wilgenboom`
- **WHEN** integriq's exchange refuses the code
- **THEN** the parent lands on `/apps/portaliq/portal?portal=wilgenboom#signin=failed`
- @e2e exclude covered by PHPUnit `BrokerSessionControllerTest::testAFailedLoginLandsOnThePortalItStartedFrom` and `BrokerLoginTest::testCallbackRefusesANon200Exchange`

### Requirement: The relay state travels under integriq's name (REQ-BEL-009)

The broker start SHALL send the relay state to integriq as `relayState`. The callback SHALL read the relay state from `relayState`, and from `state` when `relayState` is absent.

#### Scenario: Integriq hands the relay state back
- **GIVEN** a broker login whose state row was written for relay state `r-1`
- **WHEN** integriq redirects to the callback with `code` and `relayState=r-1`
- **THEN** the callback spends the state row for `r-1`
- @e2e exclude covered by PHPUnit `BrokerSessionControllerTest::testTheCallbackReadsIntegriqsRelayState` and `BrokerLoginTest::testStartRedirectsWithRelayState`

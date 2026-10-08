# portal-broker-envelope-login Specification

## Purpose
A resident signs in with DigiD, a business user with eHerkenning, and an EU visitor with eIDAS, even when the organisation runs no OIDC broker of its own. Portaliq redeems integriq's signed subject envelope and mints its own portal session from it. Closes portaliq matrix rows `sig-digid`, `sig-eherkenning`, `cmp-sig-eidas`, `sib-decidiq-plt-02` and decidiq matrix row `plt-02`.

## Requirements

### Requirement: The organisation chooses the login route per provider (REQ-BEL-001)

Portaliq SHALL let an administrator choose, per organisation and per provider, between the `oidc` route and the `broker` route. A provider with no choice recorded SHALL use `oidc`. The login screen SHALL show a provider only when its chosen route is fully configured, and SHALL start the login on that route. The consumer secret SHALL never appear in any response.

#### Scenario: A resident of a broker-routed organisation sees the DigiD button
- **GIVEN** an organisation whose DigiD provider is routed to the broker with every broker field set
- **WHEN** a resident opens the portal login screen
- **THEN** a DigiD button is shown, and pressing it opens `/portal/api/session/broker/start`

#### Scenario: An incomplete broker route shows no button
- **GIVEN** an organisation whose DigiD provider is routed to the broker without an exchange address
- **WHEN** a resident opens the portal login screen
- **THEN** no DigiD button is shown

#### Scenario: Existing organisations keep the OIDC route
- **GIVEN** an organisation with an OIDC DigiD configuration and no route choice recorded
- **WHEN** a resident presses the DigiD button
- **THEN** the login starts at `/portal/api/session/oidc/start` as before

### Requirement: The broker start binds the login to one organisation and one provider (REQ-BEL-002)

`GET /portal/api/session/broker/start` SHALL write a single-use state row recording the organisation, the provider, the route `broker`, a relay state and the return path, and SHALL then redirect the browser to integriq's start address with the organisation, the provider, the requested trust level, portaliq's consumer id and the relay state. It SHALL accept the organisation as `org` or resolve it from `portal`. It SHALL refuse a provider not routed to the broker with the same failure as any other refusal.

#### Scenario: The site renderer's link starts a broker login
- **GIVEN** a portal page whose DigiD link carries `portal=<slug>` and no `org`
- **WHEN** a resident follows it
- **THEN** the state row records the portal's organisation and the browser is sent to integriq

#### Scenario: An OIDC-routed provider cannot be started on the broker route
- **GIVEN** an organisation whose eHerkenning provider is routed to `oidc`
- **WHEN** a request opens `/portal/api/session/broker/start?provider=eherkenning`
- **THEN** no state row is written and the browser lands on the login screen with the failure message

### Requirement: The callback redeems the code once, over the authenticated exchange (REQ-BEL-003)

`GET /portal/api/session/broker/callback` SHALL consume the state row once and SHALL refuse a row written for the `oidc` route. It SHALL post the code and portaliq's consumer id to integriq's exchange address, authenticated with the consumer secret, and SHALL accept only a 200 response carrying an envelope. A second use of the same state SHALL fail.

#### Scenario: A replayed callback is refused
- **GIVEN** a broker callback that has already completed
- **WHEN** the same `state` and `code` arrive a second time
- **THEN** no session is minted and the browser lands on the login screen with the failure message

#### Scenario: A refused exchange ends the login
- **GIVEN** a valid state row
- **WHEN** integriq's exchange answers 401
- **THEN** no account is created, no session is minted, and the failure message is shown

### Requirement: Every claim portaliq acts on is checked (REQ-BEL-004)

Before acting on an envelope, portaliq SHALL check that `use` is `idp-envelope`, `iss` is `openconnector-idp-broker`, `audience` is portaliq's consumer id, `organisation` is the organisation of the state row, `provider` is the provider of the state row, and `exp` is in the future and at most 60 seconds after `iat`. Any mismatch SHALL end the login. The envelope SHALL NOT be stored and SHALL NOT be accepted as a bearer.

#### Scenario: An envelope for another organisation is refused
- **GIVEN** a login started for organisation `gemeente-x`
- **WHEN** the exchange returns an envelope whose `organisation` is `gemeente-y`
- **THEN** no session is minted and the failure message is shown

#### Scenario: An envelope for another provider is refused
- **GIVEN** a login started for DigiD
- **WHEN** the exchange returns an envelope whose `provider` is `eherkenning`
- **THEN** no session is minted and the failure message is shown

#### Scenario: A leaked envelope is not a session
- **GIVEN** an envelope returned by the exchange
- **WHEN** it is presented to `/portal/api/session` as a bearer
- **THEN** the portal answers as for an anonymous visitor

### Requirement: The envelope becomes an ordinary portal session (REQ-BEL-005)

Portaliq SHALL find or create the `portalAccount` with `identityType` set to the envelope's `provider` and `identityRef` set to the envelope's `sub`, with the audience of the provider's preset. It SHALL mint the session through `PortalSessionService::issueSession()` with the envelope's `trust`, normalising an unknown value to `low`. A collection or action whose `minTrust` the trust satisfies SHALL open for that session.

#### Scenario: A resident signs in with DigiD through the broker
- **GIVEN** an organisation routed to the broker and an integriq envelope with `provider` `digid`, `trust` `substantial`
- **WHEN** the resident completes the login
- **THEN** the portal shows them signed in with audience `client` and trust `substantial`

#### Scenario: Confidential papers open at substantial trust
- **GIVEN** a contribution collection with `minTrust` `substantial`
- **WHEN** a council member signs in with eHerkenning through the broker at `substantial`
- **THEN** the collection is listed and its objects are readable

#### Scenario: An unknown trust value is under-privileged
- **GIVEN** an envelope whose `trust` is not `low`, `substantial` or `high`
- **WHEN** the login completes
- **THEN** the session's trust is `low`

### Requirement: A failed login returns to the login screen without a reason (REQ-BEL-006)

Every failure of the broker start or callback SHALL redirect the browser to the portal with the fragment `#signin=failed` and nothing else. The login screen SHALL remove the fragment and show one message, whatever the cause: "Signing in did not work. Try again or choose another way in." (Dutch: "Inloggen is niet gelukt. Probeer het opnieuw of kies een andere manier.")

#### Scenario: Two different failures look the same
- **GIVEN** one login refused for an expired state and another refused for a wrong organisation claim
- **WHEN** the resident lands back on the portal after each
- **THEN** both show the same message and the same address

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

### Requirement: A portal offers its own organisation's sign-in

When the portal SPA is served for a resolved portal and no `?org=` is given, the runtime config MUST offer the login providers of the portal's own `organisation`. The runtime config MUST carry `signinOrganisation`, the organisation a login starts with (`?org=` when given, else the portal's `organisation`), and the SPA MUST start every login and silent sign-in with it, never with the portal's slug. The runtime config MUST carry `devLogin`, true only when the server accepts the dev login, and the SPA MUST show the dev login button only then.

#### Scenario: A parent signs in on the school portal's own address

- GIVEN a portal with slug `wilgenboom` whose `organisation` has a DigiD broker configured
- WHEN a parent opens `/apps/portaliq/portal?portal=wilgenboom` and chooses DigiD
- THEN the login starts for the portal's organisation and the parent lands signed in
- @e2e learniq `tests/e2e/po-parent-flows.spec.ts`

#### Scenario: No test button for residents

- GIVEN an instance without `debug` and without `dev_login_enabled`
- WHEN a resident opens the portal login screen
- THEN no dev login button is shown
- @e2e exclude covered by PHPUnit `PortalRuntimeConfigResolverTest::testTheDevLoginIsOfferedOnlyWhereItIsEnabled` and node `tests/broker-login.spec.mjs`

### Requirement: A login returns to the portal it started from

The SPA MUST send the serving portal's slug with a login start, and the OIDC start MUST record a return address with `?portal=<slug>` when that slug resolves to a portal, so the portal's title and branding survive the sign-in. An unknown slug MUST return to the plain portal address.

#### Scenario: The parent lands back on the school portal
- GIVEN a parent who starts DigiD on `/apps/portaliq/portal?portal=wilgenboom`
- WHEN the broker sends her back
- THEN she lands on `/apps/portaliq/portal?portal=wilgenboom` and the header shows the portal's title
- @e2e exclude covered by PHPUnit `SessionControllerTest::testALoginStartedFromAPortalReturnsToIt`; checked live on the school portal

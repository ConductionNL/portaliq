---
status: proposed
---

# Spec: portal-broker-envelope-login

## Purpose

A resident signs in with DigiD, a business user with eHerkenning, and an EU visitor with eIDAS, even when the organisation runs no OIDC broker of its own. Portaliq redeems integriq's signed subject envelope and mints its own portal session from it. Closes portaliq matrix rows `sig-digid`, `sig-eherkenning`, `cmp-sig-eidas`, `sib-decidiq-plt-02` and decidiq matrix row `plt-02`.

## ADDED Requirements

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

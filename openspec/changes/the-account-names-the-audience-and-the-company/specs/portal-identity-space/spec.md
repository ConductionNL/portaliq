## ADDED Requirements

### Requirement: An existing account's audience wins over the sign-in route's

When a sign-in (the broker route or the OIDC route) finds an existing portal account for the
identity, the session MUST carry that account's stored audience, and the role `<audience>:read`,
whatever audience the provider preset or the organisation's claim map proposes. Only an account
created by that sign-in MUST take the proposed audience. An account whose stored audience is empty
MUST take the proposed one.

#### Scenario: An invited employer signs in with eHerkenning
- **GIVEN** an app invited Linda as `employer`, and her account holds identity type `eherkenning`
- **WHEN** she signs in through the broker, whose eHerkenning preset proposes `supplier`
- **THEN** her session's audience is `employer` and its role `employer:read`
- @e2e exclude server sign-in, covered by PHPUnit `BrokerLoginTest`, `SessionControllerTest` and `PortalAccountServiceTest`; the live proof is learniq's employer board check

#### Scenario: A first sign-in creates an account with the proposed audience
- **GIVEN** no account exists for an eHerkenning identity
- **WHEN** the person signs in
- **THEN** a new account with audience `supplier` is created and the session carries `supplier`
- @e2e exclude server sign-in, covered by PHPUnit `PortalAccountServiceTest`

### Requirement: The session names the company the person acts for

The session answer MUST carry `organisationName`: the first non-empty string
`claims.<appId>.organisationName` on the person's account, trimmed and cut at 200 characters, and ''
when there is none. It MUST NOT fall back to the portal's organisation slug.

#### Scenario: Linda's chip names her company
- **GIVEN** learniq wrote the claim `organisationName` "Jansen Installatietechniek BV" on Linda's account
- **WHEN** the site asks for the session
- **THEN** `organisationName` is "Jansen Installatietechniek BV"
- @e2e exclude session payload, covered by PHPUnit `SessionControllerTest::testIndexNamesTheCompanyFromAClaim`; the chip itself by `tests/resident-menu-badges.spec.mjs`

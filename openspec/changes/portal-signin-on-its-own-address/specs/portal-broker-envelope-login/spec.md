## ADDED Requirements

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

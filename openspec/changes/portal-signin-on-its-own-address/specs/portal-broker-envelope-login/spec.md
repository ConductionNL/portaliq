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

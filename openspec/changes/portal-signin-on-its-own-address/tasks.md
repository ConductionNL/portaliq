# Tasks: portal-signin-on-its-own-address

- [x] **T1**: `signinOrganisation` in the runtime config, from `?org=` or the portal's `organisation`; providers resolved from it; the SPA login buttons and silent sign-in start with it
  - PHPUnit `PortalRuntimeConfigResolverTest::testAPortalOffersItsOwnOrganisationsSignIn`, `::testTheOrgParameterNamesTheSignInOrganisation`
  - node `tests/broker-login.spec.mjs`: the login starts with the sign-in organisation, not the portal slug
  - Live: learniq po-parent-flows e2e, a guardian signs in with DigiD on `?portal=wilgenboom`
- [x] **T2**: `devLogin` in the runtime config; the SPA shows the dev login button only when it is true
  - PHPUnit `PortalRuntimeConfigResolverTest::testTheDevLoginIsOfferedOnlyWhereItIsEnabled`
  - node `tests/broker-login.spec.mjs`: the dev login button shows only where the server accepts it
- [x] **T3**: A login started from a portal returns to that portal (`?portal=<slug>`), so its title and branding survive the sign-in; the server echoes only a portal that resolves
  - PHPUnit `SessionControllerTest::testALoginStartedFromAPortalReturnsToIt`
  - node `tests/broker-login.spec.mjs`: the login names the serving portal so it returns there

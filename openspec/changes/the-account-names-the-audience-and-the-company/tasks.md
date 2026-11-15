# Tasks: the-account-names-the-audience-and-the-company

- [x] 1. `findOrCreate()` returns `audience`: the existing account's own, else the proposed one.
  - PHPUnit `PortalAccountServiceTest` (`testAReturningIdentityKeepsItsOwnAudience`, `testANewIdentityTakesTheProposedAudience`)
- [x] 2. The broker route and the OIDC route mint the session with that audience and role.
  - PHPUnit `BrokerLoginTest::testAnInvitedAccountKeepsItsAudience`, `SessionControllerTest::testOidcCallbackKeepsTheAccountsOwnAudience`
- [x] 3. The session answer carries `organisationName` from an app's claim.
  - PHPUnit `SessionControllerTest::testIndexNamesTheCompanyFromAClaim`
- [ ] 4. learniq writes `organisationName` and the employer audience when it invites an employer (learniq `employer-portal-audience`). — not run: needs learniq (cross-repo)

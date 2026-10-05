# Tasks: invitation-secret-joins-the-signed-in-account

- [x] **T1**: A waiting account gets a one-time secret; only its hash is stored, with a seven-day expiry; only the app that provisioned the account may ask; asking again replaces the earlier secret
  - PHPUnit `WaitingAccountInvitationTest::testOnlyTheHashOfTheSecretIsStoredWithAWeekToUseIt`, `::testOnlyTheAppThatProvisionedAWaitingAccountMayInviteForIt`, `::testInvitingAgainReplacesTheEarlierSecret`
- [x] **T2**: `PortalAccountInvitationRequestedEvent` and its listener: the secret is mailed to the waiting account's address and never answered to the app
  - PHPUnit `PortalAccountInvitationListenerTest` (the real event class), `PortalIdentityMailerTest::testEachTemplateHasItsOwnFragment`, `::testTheWaysInOpenOnTheSite`
- [x] **T3**: Redeeming joins the waiting account into the signed-in account, once, and records it
  - PHPUnit `WaitingAccountInvitationTest::testTheSignedInPersonTakesOverTheWaitingAccount`, `::testTheSecretWorksOnce`, `::testAClaimTheAccountHoldsIsKeptAndSoIsItsOwnAddress`
- [x] **T4**: Wrong, expired, used, another organisation's and no-longer-waiting are one answer; only an active account with an identity receives
  - PHPUnit `WaitingAccountInvitationTest::testWrongExpiredAndUsedAreOneAnswer`, `::testAnInvitationOfAnotherOrganisationOpensNothing`, `::testAWaitingAccountThatIsNoLongerWaitingOpensNothing`, `::testOnlyAnActiveAccountThatSignedInThroughAnIdentityProviderReceives`
- [x] **T5**: Attempt limits: five wrong secrets lock the account for an hour, also without a cache; five lock the session
  - PHPUnit `WaitingAccountInvitationTest::testFiveWrongSecretsLockTheAccountEvenForTheRightOne`, `::testTheAccountLockHoldsWithoutACache`, `::testFiveWrongSecretsLockTheSession`
- [x] **T6**: The redeem route: a session, trust substantial or higher, the bearer's own account, a rate limit, one answer for a dead secret
  - PHPUnit `PortalAccountClaimControllerTest`
- [x] **T7**: The site keeps `#claim=` through the sign-in and hands it back once
  - `npm run check:claim-invitation` (`tests/claim-invitation.spec.mjs`)
- [x] **T8**: `portalAccount` 0.15.0: `claimTokenHash`, `claimExpiresAt`, `claimAttempts`, `claimAttemptsSince`, with Dutch and English labels
  - PHPUnit `PortaliqRegisterConfigTest`, `npm run check:schema-l10n`
- [x] **T9**: Live on a test instance: a guardian signs in without an address, follows the invitation link and sees the claim on her own account

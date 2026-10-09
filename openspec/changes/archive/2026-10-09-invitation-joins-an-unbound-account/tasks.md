# Tasks: invitation-joins-an-unbound-account

- [x] **T1**: `UnboundAccount::mayTakeOn()` and `WaitingAccountJoin::mayAdoptAudience()`: a person's own account (DigiD or eIDAS, identity reference, no `provisionedBy`, no claims, no `supplier` on either side, trust substantial or higher) may join an invitation of another audience; everything else the join asks still holds
  - PHPUnit `WaitingAccountJoinTest::testEachConditionOfTakingOnAnAudienceRefusesOnItsOwn`
- [x] **T2**: Only the redeem of a secret passes the session's trust, so only it moves an audience; the join at sign-in on an address stays within one audience
  - PHPUnit `WaitingAccountJoinTest::testAnAddressAloneNeverMovesAnAudience`, `::testOnlyTheRedeemOfASecretMovesTheAudience`
- [x] **T3**: The redeem joins the unbound account and writes the waiting account's audience with its claims; the attack cases are refused before the secret is spent
  - PHPUnit `WaitingAccountInvitationTest::testAPersonWhoSignedInBeforeSheWasInvitedTakesOnTheInvitationsAudience`, `::testOnlyAPersonsOwnUnboundAccountTakesOnAnotherAudience`, `::testAnInvitationOfAnotherOrganisationMovesNoAudience`, `::testTheAudienceOfTheSessionsAccountIsReadInItsOwnOrganisation`
- [x] **T4**: `refreshSession()` takes an optional audience; the redeem route reissues the session for the audience read from the account and answers the new bearer; a failed reissue still answers the claim
  - PHPUnit `PortalAccountClaimControllerTest::testAClaimThatMovedTheAudienceHandsBackABearerForIt`, `::testNoNewAudienceNoBearer`, `PortalSessionServiceTest::testASessionIsReissuedForTheAudienceItsAccountTookOn`, `::testAReissueForNoNewAudienceIsRefused`
- [x] **T5**: The site stores the reissued bearer and reads the session again, after a link and after a typed code
  - `npm run check:claim-invitation` (`tests/claim-invitation.spec.mjs`)
- [x] **T6**: Second review: only the mailed link moves an audience, never a paper code (M1); the allow-list of audiences per organisation, default `parent` (L1)
  - PHPUnit `WaitingAccountInvitationTest::testACodeFromALetterNeverMovesAnAudience`, `::testTheAudiencesAnUnboundAccountMayTakeOnAreTheOrganisations`
- [x] **T7**: A move mails the invited address (M1) and records an `audience` audit row with the old and the new audience (L3)
  - PHPUnit `PortalIdentityMailerTest::testTheInvitedAddressIsToldItsInvitationWasAccepted`, `WaitingAccountInvitationTest::testAPersonWhoSignedInBeforeSheWasInvitedTakesOnTheInvitationsAudience`
- [x] **T8**: The reissued session carries the new audience's role only (L2); the rotation records before it revokes, and a failed revoke never signs the person out (L4)
  - PHPUnit `PortalSessionServiceTest::testASessionIsReissuedForTheAudienceItsAccountTookOn`

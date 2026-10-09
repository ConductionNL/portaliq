# Tasks: invitation-joins-an-unbound-account

- [x] **T1**: `WaitingAccountJoin::mayAdoptAudience()`: a person's own account (DigiD or eIDAS, identity reference, no `provisionedBy`, no claims, no `supplier` on either side, trust substantial or higher) may join an invitation of another audience; everything else the join asks still holds
  - PHPUnit `WaitingAccountJoinTest::testEachConditionOfTakingOnAnAudienceRefusesOnItsOwn`
- [x] **T2**: Only the redeem of a secret passes the session's trust, so only it moves an audience; the join at sign-in on an address stays within one audience
  - PHPUnit `WaitingAccountJoinTest::testAnAddressAloneNeverMovesAnAudience`, `::testOnlyTheRedeemOfASecretMovesTheAudience`
- [x] **T3**: The redeem joins the unbound account and writes the waiting account's audience with its claims; the attack cases are refused before the secret is spent
  - PHPUnit `WaitingAccountInvitationTest::testAPersonWhoSignedInBeforeSheWasInvitedTakesOnTheInvitationsAudience`, `::testOnlyAPersonsOwnUnboundAccountTakesOnAnotherAudience`, `::testAnInvitationOfAnotherOrganisationMovesNoAudience`, `::testTheAudienceOfTheSessionsAccountIsReadInItsOwnOrganisation`
- [x] **T4**: The redeem route reissues the session for the audience read from the account and answers the new bearer; a failed reissue still answers the claim
  - PHPUnit `PortalAccountClaimControllerTest::testAClaimThatMovedTheAudienceHandsBackABearerForIt`, `::testNoNewAudienceNoBearer`, `PortalSessionServiceTest::testASessionIsReissuedForTheAudienceItsAccountTookOn`, `::testAReissueForNoNewAudienceIsRefused`
- [x] **T5**: The site stores the reissued bearer and reads the session again, after a link and after a typed code
  - `npm run check:claim-invitation` (`tests/claim-invitation.spec.mjs`)

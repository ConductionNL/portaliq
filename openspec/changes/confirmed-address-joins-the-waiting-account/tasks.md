# Tasks: confirmed-address-joins-the-waiting-account

- [x] **T1**: Following the confirmation link for an address joins the pending email-only account for that verified address in the same organisation: its claims are added to the account that confirmed and it is withdrawn
  - PHPUnit `PortalSelfServiceServiceTest::testConfirmingTheInvitedAddressJoinsTheWaitingAccount`, `::testAnAddressConfirmedBesideTheOneInUseJoinsToo`
- [x] **T2**: Only the trust of REQ-PIS-002: no join for a waiting account whose address nobody verified, one on an identity reference, one in another organisation or one on another address; no join for a link that admits nobody; no join onto an account without an identity
  - PHPUnit `PortalSelfServiceServiceTest::testAConfirmationJoinsOnlyAVerifiedEmailOnlyWaitingAccountInTheSameOrganisation`, `::testALinkThatAdmitsNobodyJoinsNothing`, `::testAnAccountWithoutAnIdentityReceivesNothing`
- [x] **T3**: A waiting account that carries a claim the account holds with another value is not joined, on confirmation and at sign-in (security review M1)
  - PHPUnit `PortalSelfServiceServiceTest::testAConflictingClaimLeavesTheWaitingAccountAlone`, `PortalAccountProvisionTest::testAJoinNeverOverwritesAClaimTheAccountAlreadyHolds`
- [x] **T4**: The join is recorded in the audit trail as `portaliq.claim`: the account that confirmed, the waiting account withdrawn, the moment
  - PHPUnit `PortalSelfServiceServiceTest::testConfirmingTheInvitedAddressJoinsTheWaitingAccount` (recorded once), `::testAConfirmationJoinsOnlyAVerifiedEmailOnlyWaitingAccountInTheSameOrganisation` (never recorded)
- [x] **T5**: `WaitingAccountJoin` takes a waiting account the caller located (`joinWaiting`) and answers which account it withdrew; the sign-in join keeps its behaviour
  - PHPUnit `PortalAccountProvisionTest` (unchanged, green)
- [x] **T6**: The join runs only when the confirmation arrives in the confirming account's own session at trust `substantial`; without a session or in another session the address is confirmed and nothing joins (security review H1)
  - PHPUnit `PortalSelfServiceServiceTest::testAVictimOpeningAnAttackersConfirmationJoinsNothing`, `::testTheJoinNeedsTheHoldersOwnSessionAtSubstantialTrust`, `PortalAccountSelfControllerTest::testTheConfirmationCarriesTheSessionItArrivedIn`
- [x] **T7**: The join refuses a waiting account of another audience, on confirmation and at sign-in (security review M3)
  - PHPUnit `PortalSelfServiceServiceTest::testAWaitingAccountOfAnotherAudienceIsNotJoined`, `PortalAccountProvisionTest::testASignInOfAnotherAudienceJoinsNothing`
- [x] **T8**: Addresses are matched without regard to case (security review L4)
  - PHPUnit `PortalSelfServiceServiceTest::testTheAddressIsMatchedWhateverItsCase`

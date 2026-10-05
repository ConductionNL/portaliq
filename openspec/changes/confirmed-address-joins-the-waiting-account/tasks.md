# Tasks: confirmed-address-joins-the-waiting-account

- [x] **T1**: Following the confirmation link for an address joins the pending email-only account for that verified address in the same organisation: its claims are added to the account that confirmed and it is withdrawn
  - PHPUnit `PortalSelfServiceServiceTest::testConfirmingTheInvitedAddressJoinsTheWaitingAccount`, `::testAnAddressConfirmedBesideTheOneInUseJoinsToo`
- [x] **T2**: Only the trust of REQ-PIS-002: no join for a waiting account whose address nobody verified, one on an identity reference, one in another organisation or one on another address; no join for a link that admits nobody; no join onto an account without an identity
  - PHPUnit `PortalSelfServiceServiceTest::testAConfirmationJoinsOnlyAVerifiedEmailOnlyWaitingAccountInTheSameOrganisation`, `::testALinkThatAdmitsNobodyJoinsNothing`, `::testAnAccountWithoutAnIdentityReceivesNothing`
- [x] **T3**: A claim the account already holds is kept
  - PHPUnit `PortalSelfServiceServiceTest::testAConfirmationNeverOverwritesAClaimTheAccountHolds`
- [x] **T4**: The join is recorded in the audit trail as `portaliq.claim`: the account that confirmed, the waiting account withdrawn, the moment
  - PHPUnit `PortalSelfServiceServiceTest::testConfirmingTheInvitedAddressJoinsTheWaitingAccount` (recorded once), `::testAConfirmationJoinsOnlyAVerifiedEmailOnlyWaitingAccountInTheSameOrganisation` (never recorded)
- [x] **T5**: `WaitingAccountJoin` takes a waiting account the caller located (`joinWaiting`) and answers which account it withdrew; the sign-in join keeps its behaviour
  - PHPUnit `PortalAccountProvisionTest` (unchanged, green)

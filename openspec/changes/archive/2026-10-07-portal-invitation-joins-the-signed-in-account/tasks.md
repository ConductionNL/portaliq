# Tasks: portal-invitation-joins-the-signed-in-account

- [x] **T1**: A sign-in found on its identity reference, with a broker-verified address, joins the pending email-only account for that address in the same organisation: its claims are added to the signed-in account and it is withdrawn
  - PHPUnit `PortalAccountProvisionTest::testAnInvitationAfterASignInJoinsTheAccountThatSignedIn`
  - Live: a fresh test guardian signs in through the DigiD stub, is invited through `learniq:portal:invite-guardian`, signs in again and holds the `learniq.guardianRef` claim on her own account
- [x] **T2**: Only the trust of REQ-PIS-002: no join without a verified address on both sides, for a pending account on an identity reference, or across organisations
  - PHPUnit `PortalAccountProvisionTest::testAJoinNeedsAVerifiedAddressOnBothSidesAndTheSameOrganisation`
- [x] **T3**: A claim the signed-in account already holds is kept
  - PHPUnit `PortalAccountProvisionTest::testAJoinNeverOverwritesAClaimTheAccountAlreadyHolds`
- [x] **T4**: The join gives the signed-in account the invited address when it has none, so the portal stops asking for an address the person was invited on and notifications have somewhere to go; an address of her own is kept
  - PHPUnit `PortalAccountProvisionTest::testTheJoinCarriesTheInvitedAddressSoThePortalStopsAskingForOne`, `::testTheJoinKeepsAnAddressTheAccountAlreadyHas`
  - Live finding on :8090: the prompt stood on every signed-in page for an invited guardian

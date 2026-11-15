## ADDED Requirements

### Requirement: A confirmed address joins the waiting account for it (REQ-PIS-006)

When a person follows the confirmation link Portaliq mailed to an e-mail address, Portaliq SHALL confirm the address as before. Portaliq SHALL join a waiting account only when all of these hold: the confirmation arrives with a bearer whose `subjectRef` is the confirming account's, whose organisation is the account's, and whose trust is `substantial` or higher; the confirming account is `active` and carries an identity reference. Portaliq SHALL then look for a `pending` account with that email (compared without regard to case), `verifiedEmail = true`, no identity reference, the same organisation and the same audience. When one exists and carries no claim the confirming account holds with another value, Portaliq SHALL add its claims to the account that confirmed the address and SHALL withdraw the pending account with a reason. Any other pending account SHALL stay pending. Portaliq SHALL record the join in the audit trail with the account that confirmed, the account withdrawn and the moment. A join that fails or is refused SHALL NOT undo the confirmation. A link that is unknown, expired or already used SHALL join nothing.

#### Scenario: A guardian confirms the invited address in her own session
- **GIVEN** a guardian whose sign-in carried no e-mail address, so she holds a new account without claims
- **AND** a `pending` account for the address the school invited her on, verified, for her audience, carrying `claims.learniq.guardianRef`
- **WHEN** she adds that address in the portal and follows the confirmation link while signed in at trust `substantial`
- **THEN** her own account carries `claims.learniq.guardianRef`, the pending account is `void`, and the audit trail holds one `portaliq.claim` row naming both
- @e2e exclude the confirmation secret travels by mail only; covered by PHPUnit `PortalSelfServiceServiceTest::testConfirmingTheInvitedAddressJoinsTheWaitingAccount` and checked live on a test instance

#### Scenario: A victim who opens an attacker's confirmation joins nothing
- **GIVEN** an attacker who added the victim's address to the attacker's own account, and a `pending` account for that address carrying the victim's claims
- **WHEN** the victim opens the confirmation link without a session, or in the victim's own session
- **THEN** the address is confirmed on the attacker's account, the pending account stays `pending`, the attacker's account gains no claim and no join is recorded
- @e2e exclude a security property of the server; covered by PHPUnit `PortalSelfServiceServiceTest::testAVictimOpeningAnAttackersConfirmationJoinsNothing` and checked live on a test instance

#### Scenario: A low-trust or foreign session joins nothing
- **GIVEN** the account holder's own session at trust `low`, or with no trust, or a session of the same subject in another organisation
- **WHEN** the confirmation link is followed in that session
- **THEN** the address is confirmed and the pending account stays `pending`
- @e2e exclude covered by PHPUnit `PortalSelfServiceServiceTest::testTheJoinNeedsTheHoldersOwnSessionAtSubstantialTrust`

#### Scenario: Another address joins nothing
- **GIVEN** a signed-in account and a `pending` account on a different address
- **WHEN** the person confirms her own address
- **THEN** her address is confirmed and the pending account stays `pending`
- @e2e exclude covered by PHPUnit `PortalSelfServiceServiceTest::testAConfirmationJoinsOnlyAVerifiedEmailOnlyWaitingAccountInTheSameOrganisation`

#### Scenario: The address matches whatever its case
- **GIVEN** a `pending` account for `ouder@example.org`
- **WHEN** the person adds and confirms `Ouder@Example.ORG` in her own session
- **THEN** the pending account is joined
- @e2e exclude covered by PHPUnit `PortalSelfServiceServiceTest::testTheAddressIsMatchedWhateverItsCase`

#### Scenario: A waiting account nobody may claim by address is left alone
- **GIVEN** a `pending` account on the confirmed address that is unverified, sits on an identity reference, belongs to another organisation or is for another audience
- **WHEN** the person follows the confirmation link in her own session
- **THEN** that account stays `pending` and no join is recorded
- @e2e exclude covered by PHPUnit `PortalSelfServiceServiceTest::testAConfirmationJoinsOnlyAVerifiedEmailOnlyWaitingAccountInTheSameOrganisation` and `::testAWaitingAccountOfAnotherAudienceIsNotJoined`

#### Scenario: A link that admits nobody joins nothing
- **GIVEN** a `pending` account for an address, and a confirmation link for that address that is expired, unknown or already used
- **WHEN** the link is followed
- **THEN** the answer is the one refusal every dead link gets and the pending account stays `pending`
- @e2e exclude covered by PHPUnit `PortalSelfServiceServiceTest::testALinkThatAdmitsNobodyJoinsNothing`

#### Scenario: A conflicting claim leaves the waiting account alone
- **GIVEN** an account with `claims.learniq.guardianRef = guardian-1`
- **AND** a `pending` account for the address with `claims.learniq.guardianRef = guardian-2`
- **WHEN** the person confirms that address in her own session
- **THEN** her account still reads `guardian-1` only, the pending account stays `pending` for its real holder, and the address is confirmed
- **AND** a pending account whose `guardianRef` equals the one she holds is joined, and its other claims arrive
- @e2e exclude covered by PHPUnit `PortalSelfServiceServiceTest::testAConflictingClaimLeavesTheWaitingAccountAlone`

### Requirement: The join at sign-in respects audience and held claims (REQ-PIS-010)

The join a sign-in with a verified address runs (REQ-PIS-005, portal-invitation-joins-the-signed-in-account) SHALL refuse a waiting account for another audience, and one that carries a claim the signed-in account holds with another value. Both stay `pending`.

#### Scenario: A sign-in of another audience joins nothing
- **GIVEN** a supplier account in the organisation and a `pending` parent account with the supplier's verified address
- **WHEN** the supplier signs in with that address
- **THEN** the parent account stays `pending` and the supplier gains no claim
- @e2e exclude covered by PHPUnit `PortalAccountProvisionTest::testASignInOfAnotherAudienceJoinsNothing`

#### Scenario: A sign-in with a conflicting claim joins nothing
- **GIVEN** a signed-in account with `claims.learniq.guardianRef = guardian-1` and a `pending` account with `guardian-2` on her verified address
- **WHEN** she signs in with that address
- **THEN** her account keeps `guardian-1` only and the pending account stays `pending`
- @e2e exclude covered by PHPUnit `PortalAccountProvisionTest::testAJoinNeverOverwritesAClaimTheAccountAlreadyHolds`

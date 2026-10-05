## ADDED Requirements

### Requirement: A confirmed address joins the waiting account for it (REQ-PIS-006)

When a person follows the confirmation link Portaliq mailed to an e-mail address, and the account that asked for the confirmation is `active` and carries an identity reference, Portaliq SHALL look for a `pending` account with that email, `verifiedEmail = true`, no identity reference and the same organisation. When one exists, Portaliq SHALL add its claims to the account that confirmed the address, keeping every claim that account already holds, and SHALL withdraw the pending account with a reason. Any other pending account SHALL stay pending. Portaliq SHALL record the join in the audit trail with the account that confirmed, the account withdrawn and the moment. A join that fails SHALL NOT undo the confirmation. A link that is unknown, expired or already used SHALL join nothing.

#### Scenario: A guardian who signed in without an address confirms the invited one
- **GIVEN** a guardian whose sign-in carried no e-mail address, so she holds a new account without claims
- **AND** a `pending` account for the address the school invited her on, verified, carrying `claims.learniq.guardianRef`
- **WHEN** she adds that address in the portal and follows the confirmation link
- **THEN** her own account carries `claims.learniq.guardianRef`, the pending account is `void`, and the audit trail holds one `portaliq.claim` row naming both
- @e2e exclude the confirmation secret travels by mail only; covered by PHPUnit `PortalSelfServiceServiceTest::testConfirmingTheInvitedAddressJoinsTheWaitingAccount` and checked live on a test instance

#### Scenario: Another address joins nothing
- **GIVEN** a signed-in account and a `pending` account on a different address
- **WHEN** the person confirms her own address
- **THEN** her address is confirmed and the pending account stays `pending`
- @e2e exclude covered by PHPUnit `PortalSelfServiceServiceTest::testAConfirmationJoinsOnlyAVerifiedEmailOnlyWaitingAccountInTheSameOrganisation`

#### Scenario: A waiting account nobody may claim by address is left alone
- **GIVEN** a `pending` account on the confirmed address that is unverified, sits on an identity reference, or belongs to another organisation
- **WHEN** the person follows the confirmation link
- **THEN** that account stays `pending` and no join is recorded
- @e2e exclude covered by PHPUnit `PortalSelfServiceServiceTest::testAConfirmationJoinsOnlyAVerifiedEmailOnlyWaitingAccountInTheSameOrganisation`

#### Scenario: A link that admits nobody joins nothing
- **GIVEN** a `pending` account for an address, and a confirmation link for that address that is expired, unknown or already used
- **WHEN** the link is followed
- **THEN** the answer is the one refusal every dead link gets and the pending account stays `pending`
- @e2e exclude covered by PHPUnit `PortalSelfServiceServiceTest::testALinkThatAdmitsNobodyJoinsNothing`

#### Scenario: A claim already held is kept
- **GIVEN** an account with `claims.learniq.guardianRef = guardian-1`
- **AND** a `pending` account for the address with `claims.learniq.guardianRef = guardian-2` and one more claim
- **WHEN** the person confirms that address
- **THEN** her account still reads `guardian-1` and gains the claim it lacked
- @e2e exclude covered by PHPUnit `PortalSelfServiceServiceTest::testAConfirmationNeverOverwritesAClaimTheAccountHolds`

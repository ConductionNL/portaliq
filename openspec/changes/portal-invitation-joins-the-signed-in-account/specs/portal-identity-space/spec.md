## ADDED Requirements

### Requirement: An invitation after a sign-in joins the account that signed in (REQ-PIS-005)

When a sign-in matches an account on `(identityType, identityRef, organisation)` and the broker says it verified an email address, Portaliq SHALL look for a `pending` account with that email, `verifiedEmail = true`, no identity reference and the same organisation. When one exists, Portaliq SHALL add its claims to the matched account, keeping every claim the matched account already holds, and SHALL withdraw the pending account with a reason. Any other pending account SHALL stay pending. A failed write SHALL NOT block the sign-in.

#### Scenario: A guardian invited after her first sign-in sees her child
- **GIVEN** a guardian who signed in with DigiD before the school invited her
- **AND** a `pending` account for her verified address carrying `claims.learniq.guardianRef`
- **WHEN** she signs in again and the broker reports the same verified address
- **THEN** her own account carries `claims.learniq.guardianRef`, the pending account is `void`, and no second account is active
- @e2e exclude the broker round trip is stubbed; covered by PHPUnit `PortalAccountProvisionTest::testAnInvitationAfterASignInJoinsTheAccountThatSignedIn` and checked live with the DigiD stub

#### Scenario: An address nobody verified joins nothing
- **GIVEN** a signed-in account and a `pending` account whose address was not verified
- **WHEN** a sign-in reports that address as verified
- **THEN** both accounts stay as they were
- @e2e exclude covered by PHPUnit `PortalAccountProvisionTest::testAJoinNeedsAVerifiedAddressOnBothSidesAndTheSameOrganisation`

#### Scenario: A claim already held is kept
- **GIVEN** a signed-in account with `claims.learniq.guardianRef = guardian-1`
- **AND** a `pending` account for her verified address with `claims.learniq.guardianRef = guardian-2`
- **WHEN** she signs in with that verified address
- **THEN** her account still reads `guardian-1` and gains any claim it lacked
- @e2e exclude covered by PHPUnit `PortalAccountProvisionTest::testAJoinNeverOverwritesAClaimTheAccountAlreadyHolds`

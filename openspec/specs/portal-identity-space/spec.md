# portal-identity-space Specification

## Purpose
A citizen or a company is a portal identity before their first login, so a
case filed at the desk belongs to someone and is waiting under their name
when they log in. Requested by the dossiq competitor analysis, register
row Q1.14.

## Requirements

### Requirement: An account can be provisioned before any login (REQ-PIS-001)

`portalAccount.status` SHALL gain `pending`. `PortalAccountService::provision()`
SHALL create a `pending` account from an identity reference or a verified
email, SHALL return the existing account when one matches
`(identityType, identityRef, organisation)`, and SHALL refuse a call with
neither reference nor email. A `pending` account SHALL have no session and
SHALL be unreachable from the portal. Identity references SHALL be stored
through OpenRegister's formats.

#### Scenario: A clerk provisions a citizen at the desk
- **GIVEN** a staff user with the `portal.provision` action
- **WHEN** the clerk provisions a `client` for the organisation with a BSN
- **THEN** one `pending` `portalAccount` exists with `identityType = digid`, the reference stored through `BsnFormat`, and `provisionedBy` naming the clerk
- e2e: `tests/e2e/portal-identity-space.spec.ts`

#### Scenario: A pending account cannot be used
- **GIVEN** a `pending` account
- **WHEN** any portal request names its `subjectRef`
- **THEN** the response is 401 and nothing about the account is revealed
- @e2e exclude fail-closed session contract; covered by PHPUnit on `PortalSessionService` and the proxy controllers

### Requirement: First login matches the pending account (REQ-PIS-002)

The OIDC callback SHALL, before creating an account, match a `pending`
account on `(identityType, identityRef, organisation)` and activate it,
reusing its `subjectRef`. When no identity match exists and the envelope
carries a verified email, it SHALL match a `pending` account with that
email and `verifiedEmail = true`. Any other pending account SHALL stay
pending.

#### Scenario: The provisioned citizen logs in for the first time
- **GIVEN** a `pending` account for BSN X and a DigiD login yielding an envelope for BSN X
- **WHEN** the callback completes
- **THEN** that account is `active`, its `subjectRef` is the session's subject, and no second account exists
- @e2e exclude the broker round trip is stubbed; covered by PHPUnit on the callback matching with a stub envelope

#### Scenario: A different person does not inherit the account
- **GIVEN** a `pending` account for BSN X
- **WHEN** a login for BSN Y completes
- **THEN** a new account for Y exists and X's account stays `pending`
- @e2e exclude covered by the same PHPUnit suite

### Requirement: The owning app writes its claim through a typed event (REQ-PIS-003)

Portaliq SHALL handle `PortalAccountClaimRequestedEvent` by writing
`claims.<appId>.<claimName>` on the named account server-side, taking
`appId` from the dispatching app's context, and SHALL answer the result
slot. Client input SHALL never reach `claims` (contract rule). Portaliq
SHALL handle `PortalAccountProvisionRequestedEvent` by calling
`provision()` and answering the `subjectRef`.

#### Scenario: dossiq links a case's requester to the account
- **GIVEN** a `pending` account and a case whose requester it is
- **WHEN** dossiq dispatches the claim event with `linkedRequesterId`
- **THEN** the account carries `claims.dossiq.linkedRequesterId` and the result slot reads `ok`
- @e2e exclude cross-app typed event; covered by PHPUnit with a stub dispatcher

### Requirement: "My cases" lists what the claim scopes (REQ-PIS-004)

Portaliq SHALL render a "My cases" page for the `client` audience over the
collections the contributions mark `kind: cases`, each scoped by its
`scopeClaim`, so a case attached before the first login is listed on the
first login. The link page SHALL keep working and SHALL offer login
UNCONDITIONALLY, to every reader, whether or not the subject holds an
account.

The conditional version of this sentence, "offer login when the case's
subject has an account", is REFUSED and must not be restored. The page is
`#[PublicPage]` and its reader is whoever holds the link, which can be
forwarded. Varying the offer on whether a named person holds an account
tells that holder something about that person, and comparing two links
tells them which subjects have accounts. That is account enumeration, and
it is the same failure as a login form that says whether an email is
registered.

The unconditional offer costs nothing: a reader who has no account follows
it and is told how to get one, which is the same page they need anyway. The
scenario below is unchanged, because it only ever asked that a login link be
offered.

#### Scenario: The case filed at the desk is there on first login
- **GIVEN** a case attached to a `pending` account by claim, and that person's first login
- **WHEN** they open "My cases"
- **THEN** the case is listed
- e2e: `tests/e2e/portal-identity-space.spec.ts`

#### Scenario: The token still works
- **GIVEN** a case shared by token to the same person
- **WHEN** the token page is opened without a session
- **THEN** the case status renders as today and a login link is offered
- e2e: `tests/e2e/portal-identity-space.spec.ts`

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

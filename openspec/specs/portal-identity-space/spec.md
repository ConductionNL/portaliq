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

### Requirement: A waiting account's invitation can be a code for a paper letter (REQ-PIS-009)

When `PortalAccountInvitationRequestedEvent` names the channel `letter`, Portaliq SHALL mint a code of twelve characters from an alphabet of 32 that leaves out `0`, `O`, `1` and `I`, SHALL store only its HMAC-SHA256 hash, keyed with a key derived from the instance's `secret`, with an expiry seven days ahead, SHALL answer the code in the event in three groups of four, and SHALL mail nothing. An instance without a secret SHALL issue and accept no code. The conditions of REQ-PIS-007 on the account and the dispatching app SHALL apply, except that the account needs no e-mail address: nothing is mailed. A waiting account SHALL have one live secret: minting a code SHALL end an earlier link, and minting a link SHALL end an earlier code. The redeem route of REQ-PIS-008 SHALL accept the code under the same conditions, answers and attempt limits as a link's secret, and SHALL ignore case, spaces and dashes in what was typed. The portal's site SHALL offer a signed-in person a labelled field for the code on "My account", SHALL show one sentence for a wrong, an expired and a used code, and SHALL read the account again after a right one. When a code's join brings the invited address onto an account without one, Portaliq SHALL write it with `verifiedEmail = false`.

#### Scenario: The stored code hash is keyed
- **GIVEN** a code issued on an instance with a secret
- **WHEN** the stored hash is compared with the plain SHA-256 of the code
- **THEN** they differ, and the code does not open the account under another instance secret
- @e2e exclude covered by PHPUnit `InvitationCodeTest::testTheStoredHashIsKeyedWithTheInstanceSecret` and `WaitingAccountInvitationTest::testTheCodeHashIsKeyedWithTheInstanceSecret`

#### Scenario: A code brings the address unverified
- **GIVEN** a guardian with no address of her own, and a waiting account with the address the school entered
- **WHEN** she types the code from the letter
- **THEN** her account carries that address with `verifiedEmail = false`; after a mailed link it would be `true`
- @e2e exclude covered by PHPUnit `WaitingAccountInvitationTest::testACodeBringsTheAddressUnverifiedAndALinkVerified`

#### Scenario: A guardian types the code from the school's letter
- **GIVEN** a `pending` account carrying `claims.learniq.guardianRef` and the hash of a code
- **AND** a guardian signed in at trust level substantial, on an account without claims
- **WHEN** she types the code on "My account", in lower case and without dashes
- **THEN** her own account carries `claims.learniq.guardianRef`, the pending account is `void`, the audit trail holds one `portaliq.claim` row, and the page reads her account again
- @e2e exclude the code is handed over on paper; covered by PHPUnit `WaitingAccountInvitationTest::testTheCodeIsRedeemedHoweverItIsTyped`, `tests/claim-invitation.spec.mjs`, and checked live on a test instance

#### Scenario: The code is printed by the app and never mailed
- **GIVEN** a `pending` account learniq provisioned
- **WHEN** learniq dispatches the event on the channel `letter`
- **THEN** the event answers `code` with the code in three groups of four, the account stores only its hash, and no mail leaves
- @e2e exclude covered by PHPUnit `PortalAccountInvitationListenerTest::testALetterGetsACodeAndNoMail` and `WaitingAccountInvitationTest::testACodeForALetterIsStoredAsAHashAndShownInGroups`

#### Scenario: Guessing a code locks the account
- **GIVEN** a signed-in account that typed five wrong codes inside an hour
- **WHEN** it types the right code, from any session
- **THEN** the answer is `429` and the pending account stays `pending`
- @e2e exclude covered by PHPUnit `WaitingAccountInvitationTest::testAWrongCodeCountsAndFiveLockTheAccount`

#### Scenario: A wrong, an expired and a used code read the same
- **GIVEN** a signed-in guardian on "My account"
- **WHEN** she types a wrong code, an expired one, or one that was used
- **THEN** she reads one sentence, that the code is not right or no longer valid
- @e2e exclude covered by PHPUnit `WaitingAccountInvitationTest::testACodeWorksOnceAndExpiresLikeALink` and `tests/claim-invitation.spec.mjs`

#### Scenario: A new link ends the code
- **GIVEN** a `pending` account with a code
- **WHEN** the app asks for a mailed invitation for it
- **THEN** the code no longer works and the link does
- @e2e exclude covered by PHPUnit `WaitingAccountInvitationTest::testThereIsOneLiveSecretALinkEndsACodeAndACodeEndsALink`

### Requirement: The invitation fields are readable by administrators only (REQ-PIS-011)

The `portalAccount` fields `claimTokenHash`, `claimCodeHash`, `claimExpiresAt`, `claimAttempts` and `claimAttemptsSince` SHALL carry OpenRegister property authorization `read: [admin]`, `update: [admin]`. A signed-in Nextcloud user who is not an administrator SHALL read a `portalAccount` without them. Portaliq's own reads and writes, which bypass object authorization, SHALL still see them.

#### Scenario: An ordinary Nextcloud user reads an account
- **GIVEN** a `portalAccount` with an invitation code and attempt count
- **WHEN** a signed-in Nextcloud user outside the `admin` group reads it through the OpenRegister API
- **THEN** the answer has none of the five `claim*` fields
- @e2e exclude an OpenRegister read as a Nextcloud user; covered by PHPUnit `PortaliqRegisterConfigTest::testTheInvitationFieldsAreReadableByAdministratorsOnly` and checked live on a test instance with a non-admin user

### Requirement: An app has Portaliq mail the invitation of a waiting account (REQ-PIS-007)

Portaliq SHALL handle `PortalAccountInvitationRequestedEvent` by minting a one-time secret for the named account and mailing it to that account's own email address, inside a link to the portal's site that carries the secret in the fragment. Portaliq SHALL store only the SHA-256 hash of the secret, with an expiry seven days after it was minted. Portaliq SHALL issue a secret only when the account is `pending`, has no identity reference, has an email address, and was provisioned by the dispatching app. Minting again SHALL replace the earlier secret. The event SHALL answer `sent`, `not_sent` or `refused` and SHALL NOT carry the secret.

#### Scenario: The school invites a guardian and she gets a mail
- **GIVEN** a `pending` account learniq provisioned for a guardian's address
- **WHEN** learniq dispatches `PortalAccountInvitationRequestedEvent` for it
- **THEN** one mail goes to that address with a link ending in `#claim=<secret>`, the account stores the hash and an expiry seven days ahead, and the event answers `sent`
- @e2e exclude the secret leaves by mail only; covered by PHPUnit `PortalAccountInvitationListenerTest::testTheSecretIsMailedToTheWaitingAccountsAddressAndNeverAnswered` and `WaitingAccountInvitationTest::testOnlyTheHashOfTheSecretIsStoredWithAWeekToUseIt`

#### Scenario: Another app cannot invite for the account
- **GIVEN** a `pending` account learniq provisioned
- **WHEN** another app dispatches the event for it
- **THEN** nothing is stored, nothing is mailed and the event answers `refused`
- @e2e exclude covered by PHPUnit `WaitingAccountInvitationTest::testOnlyTheAppThatProvisionedAWaitingAccountMayInviteForIt`

#### Scenario: The secret never reaches the app
- **GIVEN** an invitation that was mailed
- **WHEN** the app reads the event's answer
- **THEN** it reads the result and the expiry, and no accessor returns the secret
- @e2e exclude covered by PHPUnit `PortalAccountInvitationListenerTest::testTheSecretIsMailedToTheWaitingAccountsAddressAndNeverAnswered`

### Requirement: A signed-in person redeems an invitation and the waiting account joins theirs (REQ-PIS-008)

Portaliq SHALL offer `POST /portal/api/identity/invitation/redeem` to a portal session at trust level `substantial` or higher. The session's own account SHALL be `active`, carry an identity reference and sit in the session's organisation; otherwise Portaliq SHALL answer `403 account_cannot_receive` before it looks at the secret, and SHALL spend and count nothing. When the secret's hash matches a `pending` account with no identity reference, in the session's organisation, for the same audience, whose expiry has not passed, Portaliq SHALL lock that account, read it again, and only then empty the hash, add its claims to the session's own account and withdraw it with a reason. When the pending account carries a claim the session's account holds with another value, Portaliq SHALL answer `409 invitation_conflict` and SHALL NOT spend the secret. A secret that is unknown, expired, already used, of another organisation or of another audience SHALL get one and the same refusal. Two redeems of one secret at the same moment SHALL join once. Portaliq SHALL count wrong secrets on the caller's account under a lock, SHALL refuse every secret from an account that offered five wrong ones inside an hour, and SHALL do the same for a session that offered five. A redeem that cannot finish SHALL answer `503 try_again` without logging the secret. Portaliq SHALL record each join in the audit trail with the account, the withdrawn account, the session and the moment.

#### Scenario: A guardian who signed in through the broker follows her invitation
- **GIVEN** a guardian whose sign-in carried no e-mail address, so she holds an account without claims
- **AND** a `pending` account carrying `claims.learniq.guardianRef` and the hash of her invitation's secret
- **WHEN** she follows the link, signs in, and the site hands the secret back
- **THEN** her own account carries `claims.learniq.guardianRef`, the pending account is `void`, and the audit trail holds one `portaliq.claim` row
- @e2e exclude the secret leaves by mail only; covered by PHPUnit `WaitingAccountInvitationTest::testTheSignedInPersonTakesOverTheWaitingAccount`, `tests/claim-invitation.spec.mjs`, and checked live on a test instance

#### Scenario: The link works once
- **GIVEN** an invitation that was redeemed
- **WHEN** anyone offers the same secret again
- **THEN** the answer is the refusal every dead secret gets, and no account changes
- @e2e exclude covered by PHPUnit `WaitingAccountInvitationTest::testTheSecretWorksOnce`

#### Scenario: Wrong, expired and used cannot be told apart
- **GIVEN** a signed-in session at trust level substantial
- **WHEN** it offers a wrong secret, an expired one, and a used one
- **THEN** each answer is `403` with `invitation_not_valid`
- @e2e exclude covered by PHPUnit `WaitingAccountInvitationTest::testWrongExpiredAndUsedAreOneAnswer` and `PortalAccountClaimControllerTest::testEveryDeadSecretGetsTheSameAnswer`

#### Scenario: A session below substantial is refused before the secret is read
- **GIVEN** a portal session at trust level low
- **WHEN** it offers a valid secret
- **THEN** the answer is `403` with `trust_too_low` and the invitation is untouched
- @e2e exclude covered by PHPUnit `PortalAccountClaimControllerTest::testASessionBelowSubstantialIsRefusedBeforeTheSecretIsLookedAt`

#### Scenario: Two redeems of one secret join once
- **GIVEN** an invitation forwarded to two people who are both signed in
- **WHEN** both hand in the secret at the same moment, the second request running between the first one's read and its write
- **THEN** exactly one account receives the claims, the waiting account is withdrawn once, one join is recorded, and the other request gets the dead-secret refusal
- @e2e exclude a race on the server; covered by PHPUnit `WaitingAccountInvitationTest::testTwoRedeemsOfOneSecretJoinExactlyOnce` and `::testARedeemWhileTheWaitingAccountIsLockedChangesNothing`, and checked live on a test instance

#### Scenario: A conflicting claim is refused before the secret is spent
- **GIVEN** a signed-in account holding `claims.learniq.guardianRef = guardian-1`
- **AND** an invitation whose waiting account carries `guardianRef = guardian-2`
- **WHEN** she hands in that invitation's secret
- **THEN** the answer is `409 invitation_conflict`, her claims are unchanged, the waiting account stays `pending` with its hash, and nothing is counted or recorded
- @e2e exclude covered by PHPUnit `WaitingAccountInvitationTest::testAConflictingClaimIsRefusedBeforeTheSecretIsSpent` and checked live on a test instance

#### Scenario: Another audience cannot take over the invitation
- **GIVEN** a supplier account in the same organisation and a parent's invitation
- **WHEN** the supplier hands in the parent's secret
- **THEN** the answer is the dead-secret refusal and the invitation is not spent
- @e2e exclude covered by PHPUnit `WaitingAccountInvitationTest::testAnAccountOfAnotherAudienceCannotTakeOverTheInvitation`

#### Scenario: An account that cannot receive is told so
- **GIVEN** a session whose own account is not active, has no identity reference, or sits in another organisation
- **WHEN** it hands in any secret, right or wrong
- **THEN** the answer is `403 account_cannot_receive`, nothing is spent or counted, and the site keeps the secret in the tab
- @e2e exclude covered by PHPUnit `WaitingAccountInvitationTest::testOnlyAnActiveAccountThatSignedInThroughAnIdentityProviderReceives`, `PortalAccountClaimControllerTest::testEachRefusalAboutTheCallerHasItsOwnAnswer` and `tests/claim-invitation.spec.mjs`

#### Scenario: Guessing locks the account
- **GIVEN** an account that offered five wrong secrets inside an hour
- **WHEN** it offers the right one, from any session
- **THEN** the answer is `429` and the invitation is untouched, and an hour after the first wrong secret the right one works
- **AND** wrong secrets sent in parallel each count
- @e2e exclude covered by PHPUnit `WaitingAccountInvitationTest::testFiveWrongSecretsLockTheAccountEvenForTheRightOne`, `::testTheAccountLockHoldsWithoutACache` and `::testTwoWrongSecretsAtOnceCountAsTwo`

#### Scenario: A failure does not log the secret
- **GIVEN** a redeem that fails below the controller
- **WHEN** the failure is handled
- **THEN** the answer is `503 try_again`, the log names the exception class only, and the secret parameter is marked sensitive
- @e2e exclude covered by PHPUnit `PortalAccountClaimControllerTest::testAFailureIsLoggedWithoutTheSecret`

#### Scenario: The site keeps the invitation through the sign-in
- **GIVEN** a visitor who opens the invitation link without a session
- **WHEN** the page loads
- **THEN** the secret is gone from the address bar, she is asked to sign in, and after the sign-in the site hands the secret back once
- @e2e exclude covered by `tests/claim-invitation.spec.mjs`; the full round trip is checked live on a test instance

### Requirement: An invitation after a sign-in joins the account that signed in (REQ-PIS-005)

When a sign-in matches an account on `(identityType, identityRef, organisation)` and the broker says it verified an email address, Portaliq SHALL look for a `pending` account with that email, `verifiedEmail = true`, no identity reference and the same organisation. When one exists, Portaliq SHALL add its claims to the matched account, keeping every claim the matched account already holds, SHALL give the matched account that verified address when it has none of its own, and SHALL withdraw the pending account with a reason. Any other pending account SHALL stay pending. A failed write SHALL NOT block the sign-in.

#### Scenario: A guardian invited after her first sign-in sees her child
- **GIVEN** a guardian who signed in with DigiD before the school invited her
- **AND** a `pending` account for her verified address carrying `claims.learniq.guardianRef`
- **WHEN** she signs in again and the broker reports the same verified address
- **THEN** her own account carries `claims.learniq.guardianRef`, the pending account is `void`, and no second account is active
- @e2e exclude the broker round trip is stubbed; covered by PHPUnit `PortalAccountProvisionTest::testAnInvitationAfterASignInJoinsTheAccountThatSignedIn` and checked live with the DigiD stub

#### Scenario: The invited address becomes hers, and the portal stops asking for one
- **GIVEN** a guardian with no e-mail address on her account, who is asked for one on every page
- **AND** a `pending` account for the address the school invited her on, verified
- **WHEN** she signs in and the broker reports that address
- **THEN** her own account carries the address as verified, and she is no longer asked for one
- @e2e exclude the broker round trip is stubbed; covered by PHPUnit `PortalAccountProvisionTest::testTheJoinCarriesTheInvitedAddressSoThePortalStopsAskingForOne`

#### Scenario: An address of her own is kept
- **GIVEN** a signed-in account with its own e-mail address
- **AND** a `pending` account on a different, verified address
- **WHEN** she signs in and the broker reports that other address
- **THEN** her own address stays as it is and the claims still arrive
- @e2e exclude covered by PHPUnit `PortalAccountProvisionTest::testTheJoinKeepsAnAddressTheAccountAlreadyHas`

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

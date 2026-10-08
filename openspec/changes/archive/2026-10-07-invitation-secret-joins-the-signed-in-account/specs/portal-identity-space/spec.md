## ADDED Requirements

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

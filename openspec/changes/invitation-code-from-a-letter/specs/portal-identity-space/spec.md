## ADDED Requirements

### Requirement: A waiting account's invitation can be a code for a paper letter (REQ-PIS-009)

When `PortalAccountInvitationRequestedEvent` names the channel `letter`, Portaliq SHALL mint a code of twelve characters from an alphabet of 32 that leaves out `0`, `O`, `1` and `I`, SHALL store only its SHA-256 hash with an expiry seven days ahead, SHALL answer the code in the event in three groups of four, and SHALL mail nothing. The conditions of REQ-PIS-007 on the account and the dispatching app SHALL apply. A waiting account SHALL have one live secret: minting a code SHALL end an earlier link, and minting a link SHALL end an earlier code. The redeem route of REQ-PIS-008 SHALL accept the code under the same conditions, answers and attempt limits as a link's secret, and SHALL ignore case, spaces and dashes in what was typed. The portal's site SHALL offer a signed-in person a labelled field for the code on "My account", SHALL show one sentence for a wrong, an expired and a used code, and SHALL read the account again after a right one.

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

# portal-notifications-and-preferences Specification

## Purpose
A resident hears about a change on their case in the portal and, if they want, by e-mail or push. A message from the organisation gets an e-mail too. Every notification leads straight to the record it is about. The resident chooses per kind and per channel. Requested by TenderNed 397282; closes portaliq matrix rows `dem-tnd-notify-on-change`, `cmp-inb-email-alert`, `cas-deeplink-notify` and `cmp-inb-notif-prefs`.

## Requirements

### Requirement: A case app declares which change a resident hears about (REQ-NAP-001)

A contribution's `notifications` list SHALL accept, next to plain rule keys, a rule object naming a `ruleKey`, one of the contribution's own `collection`s and an `on` condition with a `field` that the collection projects and the operator `changed`. Portaliq SHALL drop a rule whose collection is scoped through `scopeClaim` or `via` unless the rule names its `recipients` (REQ-NAP-012), SHALL drop a rule whose field is not projected, and SHALL log it.

#### Scenario: A well-formed rule is kept
- **GIVEN** a case app declaring a rule on its cases collection for the `status` field
- **WHEN** portaliq aggregates the resident's contributions
- **THEN** the rule is kept and the plain rule keys beside it still work
- @e2e exclude Manifest normalisation; pinned by NotificationRuleNormaliserTest::testKeepsAWellFormedRule and ::testPlainStringsStillPass

#### Scenario: A rule on an unprojected field is dropped
- **GIVEN** a rule naming a field the collection does not project to residents
- **WHEN** portaliq aggregates the contributions
- **THEN** the rule is dropped and a warning names the app and the rule
- @e2e exclude Manifest normalisation; pinned by NotificationRuleNormaliserTest::testDropsAnUnprojectedField

#### Scenario: A rule on a via collection without recipients is dropped
- **GIVEN** a rule on a collection read through `via` or `scopeClaim` that names no recipients
- **WHEN** portaliq aggregates the contributions
- **THEN** the rule is dropped, and the listener does not act on it even when handed it unnormalised
- @e2e exclude Manifest normalisation; pinned by NotificationRuleNormaliserTest::testDropsARuleOnAViaCollection and ClaimAddressedChangeNoticeTest::testWithoutRecipientsAViaRuleStaysSilent

#### Scenario: A plain key nothing fires is dropped
- **GIVEN** a supplier app declaring `notifications: ["tenderPublished", "message.created", "dossiq.invoiceDue"]` as app `dossiq`
- **WHEN** portaliq aggregates the contributions
- **THEN** `message.created` and `dossiq.invoiceDue` are kept, `tenderPublished` is dropped, and a warning names the app and says how to declare the key
- @e2e exclude Manifest normalisation; pinned by NotificationRuleNormaliserTest::testDropsABareKeyNothingFires, ::testKeepsKeysSomethingFires and ::testLogsADroppedKeyWithTheApp

#### Scenario: Another app's key is dropped
- **GIVEN** app `opencatalogi` declaring `pipelinq.question.answered`
- **WHEN** portaliq aggregates the contributions
- **THEN** the key is dropped: a portalMessage carrying it is dispatched for `pipelinq` only, so it never fires for `opencatalogi`
- @e2e exclude Manifest normalisation; pinned by NotificationRuleNormaliserTest::testDropsAnotherAppsKeyAndTheKeyOfADroppedRule

### Requirement: A declared change reaches the resident's inbox (REQ-NAP-002)

When OpenRegister reports an update to a record of a collection with a change rule, and the rule's field differs between the old and the new record, portaliq SHALL write a message into the inbox of the resident whose reference the record holds at the collection's `scopeField`, and SHALL dispatch the rule's key. When the old record is not available, portaliq SHALL report nothing. A failure SHALL never fail the update.

#### Scenario: A handler changes the status
- **GIVEN** a resident with a case whose app declares a rule on `status`
- **WHEN** a handler moves the case to another status
- **THEN** the resident's portal inbox holds a new unread message saying the case has been updated
- @e2e exclude Needs a case app declaring a change rule, which the seed lacks; pinned by PortalRecordChangeListenerTest::testStatusChangeWritesAMessageAndDispatches with OpenRegister's real events

#### Scenario: An unrelated field changes
- **GIVEN** the same rule
- **WHEN** a handler changes only an internal note on the case
- **THEN** no message is written and nothing is dispatched
- @e2e exclude pinned by PortalRecordChangeListenerTest::testUnchangedFieldDoesNothing

### Requirement: A resident is not told about their own change (REQ-NAP-003)

A change portaliq writes on the resident's behalf SHALL NOT produce a change message or a change notification for that resident.

#### Scenario: The resident corrects their own answer
- **GIVEN** a rule on a field the resident may amend
- **WHEN** the resident saves a correction from the case screen
- **THEN** no change message appears in their own inbox
- @e2e exclude pinned by PortalRecordChangeListenerTest::testResidentsOwnWriteIsNotReported

### Requirement: A case app's message triggers an e-mail (REQ-NAP-004)

When OpenRegister reports a new record in a `kind: inbox` collection of an app that declares `message.created`, portaliq SHALL dispatch `message.created` for the resident at that collection's `scopeField`. Portaliq's own `portalMessage` records SHALL NOT be dispatched a second time.

#### Scenario: A handler writes to the resident
- **GIVEN** a case app with an inbox collection that declares `message.created`
- **WHEN** a handler writes a message to a resident
- **THEN** the resident receives the content-free e-mail nudge once
- @e2e exclude pinned by PortalRecordChangeListenerTest::testCaseAppMessageDispatches and ::testPortalMessageIsNotDispatchedTwice

### Requirement: A notification leads to the record (REQ-NAP-005)

A message or e-mail about a record SHALL carry a link that opens the portal on that record. The link SHALL survive a sign-in: a signed-out resident who follows it SHALL land on the record after signing in. The record SHALL be read through the resident's own scoped read, so a link to a record that is not theirs SHALL open nothing of it. An inbox message about a record SHALL show an "Open" button.

#### Scenario: From the e-mail to the case
- **GIVEN** a signed-out resident with an e-mail about their case
- **WHEN** they follow its link and sign in
- **THEN** the portal opens on that case
- e2e: `tests/e2e/inbox-notifications-and-preferences.spec.ts` (the target survives the sign-in); tests/open-record.spec.mjs

#### Scenario: A forwarded link opens nothing
- **GIVEN** a link to someone else's case
- **WHEN** a resident follows it and signs in
- **THEN** the case screen says the case is not theirs and shows none of its data
- @e2e exclude pinned by tests/open-record.spec.mjs (rowFor reads only the resident's own scoped rows)

### Requirement: The e-mail says what kind of thing happened, and nothing more (REQ-NAP-006)

The e-mail for a change rule SHALL say that something changed on the resident's record, name the collection's label and the organisation, and carry the link. It SHALL NOT carry any field value of the record.

#### Scenario: No status in the mail
- **GIVEN** a case moved to a status named "Afgewezen"
- **WHEN** the change e-mail is sent
- **THEN** the e-mail does not contain "Afgewezen" or any other field value
- @e2e exclude Mail content; pinned by NotificationDispatchJobTest::testChangeRuleTextCarriesNoCaseContent

### Requirement: The resident chooses per kind and per channel (REQ-NAP-007)

A resident SHALL be able to switch e-mail and push on or off separately for "Changes on your cases" and "New messages", for their own account only. A missing choice SHALL mean on. The existing e-mail opt-out SHALL still switch e-mail off for every kind. The portal inbox message SHALL be written whatever the choice.

#### Scenario: E-mail off for case changes
- **GIVEN** a resident who switched e-mail off for case changes
- **WHEN** a handler changes their case status
- **THEN** the inbox message is written and no e-mail is sent
- @e2e exclude pinned by NotificationDispatchJobTest::testKindEmailOffSendsNoEmail

#### Scenario: Push on for messages
- **GIVEN** a resident with a registered device who switched push on for new messages
- **WHEN** a handler writes to them
- **THEN** a push arrives, unless their quiet hours hold it back
- @e2e exclude pinned by NotificationDispatchJobTest::testKindPushOnSendsAPush

#### Scenario: Another account's choices are out of reach
- **GIVEN** a resident's session
- **WHEN** they send preferences naming another account
- **THEN** only their own account changes
- @e2e exclude pinned by PortalAccountSelfControllerTest::testPreferencesAreTheCallersOwn

### Requirement: The choices live on the inbox page (REQ-NAP-008)

The inbox page SHALL offer a "Notification settings" section with one labelled checkbox per kind and channel. The push column SHALL show only when the account has a registered device and a push transport that really delivers is bound. Saving SHALL confirm with "Your choices are saved."

#### Scenario: A resident changes a setting
- **GIVEN** a resident on the inbox page
- **WHEN** they open "Notification settings", clear "E-mail" for new messages and save
- **THEN** the page says "Your choices are saved." and the box stays cleared after a reload
- e2e: `tests/e2e/inbox-notifications-and-preferences.spec.ts`

### Requirement: A change rule may reach residents by a claim (REQ-NAP-012)

A change rule MAY declare `recipients` with a `field` of the record and a `claim` of the contributing app, as a bare name or as `<app>.<name>` with the contributing app's own id. Portaliq SHALL drop a rule whose claim names another app or whose recipients are malformed. When the rule's field changes, portaliq SHALL find the active portal accounts whose `claims.<app>.<claim>` equals the record's value at `field`, SHALL read the record as each of them through the collection's own scoped read (its `scopeField`, `scopeClaim`, `via`, `filter` and `fields`), and SHALL write the inbox message and dispatch the rule's key only for an account that may read the record. A record without a value at `field` SHALL reach nobody. Delivery SHALL use the same inbox message and dispatch as REQ-NAP-002, so the resident's channel preferences apply unchanged.

#### Scenario: The guardian who booked hears that the teacher acknowledged
- **GIVEN** a booking whose `guardianRef` is the value of Fatima's claim `claims.learniq.guardianRef`, and a rule on the bookings collection with `recipients` `{"field": "guardianRef", "claim": "guardianRef"}`
- **WHEN** the teacher moves the booking from `booked` to `acknowledged`
- **THEN** Fatima's inbox holds one new message about the booking, and the rule's key is dispatched for her account only
- **AND** an account holding the same value under another app's claim, and a withdrawn account, are not told
- @e2e exclude Needs a teacher action in learniq and a guardian portal session on one instance; pinned by ClaimAddressedChangeNoticeTest::testTheGuardianWhoseClaimTheRecordHoldsIsTold with OpenRegister's real events, and checked live on the primary-school instance (learniq claim-addressed booking notice)

#### Scenario: Another family's guardian is not told
- **GIVEN** an account holding the claim value on the record that the collection's scoped read refuses
- **WHEN** the booking changes
- **THEN** no message is written and nothing is dispatched
- @e2e exclude Absence of a message to a second family needs two guardian sessions; pinned by ClaimAddressedChangeNoticeTest::testAnAccountThatMayNotReadTheRecordIsNotTold

#### Scenario: No claim value, no message
- **GIVEN** a booking without a `guardianRef`
- **WHEN** it changes
- **THEN** no account is looked for, no message is written and nothing is dispatched
- @e2e exclude pinned by ClaimAddressedChangeNoticeTest::testNoClaimValueNoMessage

### Requirement: A change rule may say in its own words what happened (REQ-NAP-013)

A change rule MAY declare `messages`, a map from a new value of its field to a `subject` and a `body`. Each text SHALL be a non-empty string or a map of language code to string, and a `{field}` or `{field|datetime}` placeholder SHALL name a field the collection projects; portaliq SHALL drop a rule that breaks this. The message SHALL be written in the portal's language, else English, else the first text given, with placeholders filled from the record as the resident may read it; `{field|datetime}` SHALL print `d-m-Y H:i`. A new value without an entry SHALL not be reported. A rule without `messages` SHALL keep the generic text of REQ-NAP-002.

#### Scenario: The acknowledgement names the time and the teacher
- **GIVEN** the bookings rule with a Dutch message for `acknowledged` reading "De leerkracht heeft uw gesprekstijd bevestigd: {startsAt|datetime}, met {teacherName}."
- **WHEN** the teacher acknowledges the booking for 13 October 2026 at 18:00 with J. de Vries, on a portal whose language is Dutch
- **THEN** the message body reads "De leerkracht heeft uw gesprekstijd bevestigd: 13-10-2026 18:00, met J. de Vries."
- @e2e exclude pinned by ClaimAddressedChangeNoticeTest::testTheGuardianWhoseClaimTheRecordHoldsIsTold

#### Scenario: A decline carries the teacher's note
- **GIVEN** a message for `declined` with `{declineNote}`
- **WHEN** the teacher declines with the note "Ik ben ziek"
- **THEN** the message body contains "Ik ben ziek"
- @e2e exclude pinned by ClaimAddressedChangeNoticeTest::testADeclineCarriesTheTeachersNote

#### Scenario: A value without words is not reported
- **GIVEN** the same rule, with no message for `cancelled`
- **WHEN** the booking moves to `cancelled`
- **THEN** no message is written and nothing is dispatched
- @e2e exclude pinned by ClaimAddressedChangeNoticeTest::testAValueWithoutAMessageIsNotReported

#### Scenario: A placeholder on an unprojected field drops the rule
- **GIVEN** a message whose body names a field the collection does not project
- **WHEN** portaliq aggregates the contributions
- **THEN** the rule is dropped and the warning names the field
- @e2e exclude Manifest normalisation; pinned by NotificationRuleNormaliserTest::testDropsAForeignClaimAMalformedRecipientAndAnUnprojectedPlaceholder

### Requirement: A resident can delete their own inbox messages

A resident MUST be able to delete one or several of their own inbox messages. Portaliq's own notices MAY always be deleted; a message in an app's inbox MAY be deleted only when its collection declares `deletable: true`. The server MUST check that the (register, schema) is an inbox the resident may read, MUST check the trust level again, and MUST delete a row only when its scope field holds the resident's own reference alone and its tenant matches. Another resident's message, a message shared with someone else and an unknown id MUST answer the same 404 with nothing deleted. The page MUST ask for confirmation on the page itself before deleting, never with a browser dialog, and MUST say afterwards what was deleted or that a message could not be deleted.

#### Scenario: A parent deletes one message after confirming
- GIVEN a parent with a notice in Berichten
- WHEN she presses "Verwijderen" and then "Ja, verwijderen"
- THEN the notice is gone from her inbox and the page says "Het bericht is verwijderd."
- @e2e exclude pinned by the node tests in `tests/inbox-delete.spec.mjs` and `ContributionControllerTest::testDeleteMessageRemovesTheResidentsOwnNotice`; the live check on :8090 is in the PR

#### Scenario: Cancel deletes nothing
- GIVEN the question is on the page
- WHEN the parent presses "Annuleren"
- THEN nothing is deleted
- @e2e exclude pinned by the node test "deleting one message asks first on the page, and Cancel deletes nothing"

#### Scenario: Several messages at once
- GIVEN three notices
- WHEN the parent chooses "Alles selecteren", "Geselecteerde verwijderen (3)" and confirms
- THEN all three are deleted and the unread count follows
- @e2e exclude pinned by the node test "deleting the selected messages removes them, updates the count and says so"

#### Scenario: Another resident's message is never deleted
- GIVEN a message id that belongs to another resident, or is shared with one
- WHEN a parent sends a delete for it
- THEN the answer is 404 and nothing is deleted
- @e2e exclude pinned by `PortalObjectWriterDeleteTest::testItNeverDeletesARowThatIsNotTheSubjectsAlone` and `ContributionControllerTest::testDeleteMessageOfAnotherResidentIs404`

#### Scenario: An app's inbox decides
- GIVEN an app's inbox collection without `deletable: true`
- WHEN a parent sends a delete for one of its messages
- THEN the answer is 403 and the page offers no delete for it
- @e2e exclude pinned by `ContributionControllerTest::testDeleteMessageFromAnAppsInboxNeedsItsConsent` and `PortalInboxReaderTest::testEachRowSaysWhetherTheResidentMayDeleteIt`

### Requirement: Portaliq's own notices reach the resident's inbox (REQ-NAP-009)

The inbox SHALL include the resident's own `portalMessage` notices, whether or
not a contribution declares an inbox collection over them. They SHALL be read on
`subjectRef` with the bearer session's own reference, under the same
organisation rule as every other inbox source, and SHALL show their subject,
body, date, read state and record link. A notice that a declared inbox
collection also returns SHALL appear once. Marking such a notice read SHALL
write only `read`, on the bearer's own notice.

#### Scenario: An answered question reaches the inbox
- **GIVEN** a resident who asked a question from a dossier
- **WHEN** the KCC employee posts the answer
- **THEN** the resident's inbox serves an unread notice linking to the question
- **AND** after the resident marks it read, the inbox serves it as read
- e2e: `tests/e2e/woo-journey.spec.ts` J4

#### Scenario: A matched saved search reaches the inbox
- **GIVEN** a resident with a daily saved search
- **WHEN** a new publication matches it
- **THEN** the resident's inbox serves an unread notice linking to the search
- e2e: `tests/e2e/woo-journey.spec.ts` J6

#### Scenario: Another resident's notice never appears
- **GIVEN** a notice written for another resident
- **WHEN** a resident reads their inbox
- **THEN** that notice is not in it
- @e2e exclude pinned by PortalInboxReaderTest::testAnotherSubjectsPortalMessageNeverAppears; the per-row check is PortalObjectReader's own

#### Scenario: A contributed inbox still merges
- **GIVEN** a case app that declares an inbox collection
- **WHEN** the resident reads their inbox
- **THEN** its messages and portaliq's own notices are in one list, newest first
- @e2e exclude pinned by PortalInboxReaderTest::testAResidentSeesTheirOwnPortalMessagesAlongsideAContributedInbox

### Requirement: A receipt, a notification mail and a task notice are written in the portal's language only

The submission receipt (`SubmissionReceiptService`), the notification e-mail and push (`NotificationDispatchJob`) and the delivered task notice and mail (`PortalTaskDeliveryJob`) MUST each be written once, in the language of the resident's portal, chosen by `PortalNoticeLanguage` the same way change notices are (REQ-NAP-010): the portal's first locale, else Dutch. They MUST NOT join two languages with " / " or a blank line. A task notice MUST find the portal through the resident's own portal account.

#### Scenario: The receipt of a portal in English
- GIVEN the organisation's portal lists `en` as its first locale
- WHEN a resident sends a form
- THEN the receipt's subject reads "Confirmation of receipt, reference WMEBV-..." and carries no Dutch line
- @e2e exclude pinned by `SubmissionReceiptServiceTest::testTheReceiptIsInThePortalsLanguageOnly`

#### Scenario: The notification mail of a portal with no locale
- GIVEN the organisation's portal names no locale
- WHEN a notification mail is sent
- THEN subject and body are Dutch only
- @e2e exclude pinned by `NotificationDispatchJobTest::testSendsAContentFreeEmailAndLogsASentAttempt` and `::testTheEmailIsInThePortalsLanguageOnly`

#### Scenario: A task notice in the resident's portal language
- GIVEN the resident's portal account belongs to an organisation whose portal lists `en` first
- WHEN a task is delivered to their portal inbox
- THEN the notice is English only
- @e2e exclude pinned by `PortalTaskDeliveryJobTest::testAnInboxNoticeIsInThePortalsLanguageOnly` and `::testAskMailIsInThePortalsLanguageOnly`

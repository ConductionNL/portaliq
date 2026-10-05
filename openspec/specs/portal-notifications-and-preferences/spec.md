# portal-notifications-and-preferences Specification

## Purpose
A resident hears about a change on their case in the portal and, if they want, by e-mail or push. A message from the organisation gets an e-mail too. Every notification leads straight to the record it is about. The resident chooses per kind and per channel. Requested by TenderNed 397282; closes portaliq matrix rows `dem-tnd-notify-on-change`, `cmp-inb-email-alert`, `cas-deeplink-notify` and `cmp-inb-notif-prefs`.

## Requirements

### Requirement: A case app declares which change a resident hears about (REQ-NAP-001)

A contribution's `notifications` list SHALL accept, next to plain rule keys, a rule object naming a `ruleKey`, one of the contribution's own `collection`s and an `on` condition with a `field` that the collection projects and the operator `changed`. Portaliq SHALL drop a rule whose collection is scoped through `scopeClaim` or `via`, or whose field is not projected, and SHALL log it.

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

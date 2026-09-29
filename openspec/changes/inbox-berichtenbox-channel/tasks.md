# Tasks: inbox-berichtenbox-channel

## Configuration and contract

- [x] **T01**: The organisation's `messageBox: {sourceId, label}` setting; the channel exists only when both are set (REQ-MBC-001)
  - PHPUnit `PortalOrganisationConfigServiceTest::testMessageBoxNeedsSourceAndLabel`
- [x] **T02**: Accept `messageBox: {recipientProvider}` on a `kind: inbox` collection, validated like a timeline provider name (REQ-MBC-002)
  - PHPUnit `MessageBoxConfigNormaliserTest::testKeepsAWellFormedDeclaration`, `::testDropsAContractMethodName`

## Sending

- [x] **T03**: Add `messageBox` to `portalNotification.channel`, `delivered`, `read` and `simulated` to `status`, and the `externalMessageId` and `recordLink` properties (REQ-MBC-003)
  - Manual check: import the register and write a `portalNotification` with each new value through OpenRegister
- [x] **T04**: Enqueue a `messageBox` job for a new message of a declaring collection when the organisation and the resident allow it (REQ-MBC-003)
  - PHPUnit `PortalRecordChangeListenerTest::testMessageBoxJobOnlyWhenAllowed`
- [x] **T05**: In `MessageBoxDispatchJob` (its own job, see design notes): call the recipient method, guard with `class_exists`, dispatch `DigitalPostSendRequestedEvent`, log `sent` or `failed`, never keep the recipient (REQ-MBC-002, REQ-MBC-003)
  - PHPUnit `MessageBoxDispatchJobTest::testNullRecipientSendsNothing`, `::testUnhandledEventIsARefusal`, `::testRecipientIsInNoLogAndNoRow`, `::testMessageIdIsRecorded`

## Status

- [x] **T06**: `PortalDigitalPostDeliveredListener`: only `requestedBy` `portaliq`, update by `externalMessageId`, `simulated` never becomes `delivered` (REQ-MBC-004)
  - PHPUnit, built with a real `DigitalPostDeliveredEvent` when integriq is installed in the test environment: `::testDeliveredUpdatesTheRow`, `::testSimulatedStaysSimulated`, `::testOtherRequesterIsIgnored`
- [x] **T07**: `PortalInboxReader` adds `_deliveries`; `InboxPage.jsx` shows "Also sent to {label}." (REQ-MBC-004)
  - Playwright `tests/e2e/inbox-message-box-channel.spec.ts`: with integriq's log binding answering delivered, the resident's message shows the delivery line

## The resident's choice

- [x] **T08**: The "Also send letters to {label}" row in the notification settings, stored as `notificationPreferences.messageBox` (REQ-MBC-005)
  - Playwright `tests/e2e/inbox-message-box-channel.spec.ts`: switching it off means the next message gets no `messageBox` row in the notification log

## Docs and strings

- [x] **T09**: Dutch and English strings for the delivery line and the settings row; the contract docs page gains `messageBox`, the recipient method, and the rule to return null for a letter the case app sends itself
- [x] **T10**: `openspec validate inbox-berichtenbox-channel --strict`

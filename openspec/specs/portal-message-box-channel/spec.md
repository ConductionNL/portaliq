# portal-message-box-channel Specification

## Purpose
A decision or message a case app sends to a resident's portal inbox can also go to the resident's government message box, through integriq's digital post adapter. The case app decides which message and supplies the recipient; portaliq sends, records and shows the result, and never keeps the recipient's identity. Requested by TenderNed 407031; closes portaliq matrix row `dem-tnd-berichtenbox`.

## Requirements

### Requirement: The organisation turns the channel on (REQ-MBC-001)

The `messageBox` channel SHALL exist for an organisation only when it configures an integriq digital post source and a label. Without both, portaliq SHALL send nothing on this channel and SHALL show no choice for it.

#### Scenario: An organisation without the channel
- **GIVEN** an organisation with no message box source configured
- **WHEN** a resident opens their notification settings
- **THEN** no message box choice is shown

### Requirement: The case app names the recipient, and portaliq does not keep it (REQ-MBC-002)

A `kind: inbox` collection MAY declare a recipient method on its app's portal provider. Portaliq SHALL call it server-side for one message and SHALL send nothing when it returns no recipient. Portaliq SHALL NOT write the recipient identity to any object, log line or response.

#### Scenario: The case app keeps a message in the portal only
- **GIVEN** a recipient method that returns nothing for an informal message
- **WHEN** that message arrives in the resident's inbox
- **THEN** nothing is sent to the message box
- @e2e exclude Needs a case app naming a recipient method; pinned by MessageBoxDispatchJobTest::testNullRecipientSendsNothing

#### Scenario: The identity leaves no trace in portaliq
- **GIVEN** a message the case app wants delivered to the message box
- **WHEN** portaliq sends it
- **THEN** the portal notification log, the portal logs and every portal response hold no citizen service number
- @e2e exclude A negative over logs and rows; pinned by MessageBoxDispatchJobTest::testRecipientIsInNoLogAndNoRow

### Requirement: Portaliq asks integriq to send, and records the answer (REQ-MBC-003)

For a message whose collection declares the channel, in an organisation that offers it, for a resident who did not switch it off, portaliq SHALL dispatch integriq's `DigitalPostSendRequestedEvent` with the organisation's source, the message's subject, body and attachments, and `requestedBy` `portaliq`. When integriq is not installed, or nobody handles the event, portaliq SHALL record a refusal, never a send. It SHALL record integriq's message id with the attempt. The letter's text and subject SHALL come from the fields the collection declares (`messageBox.bodyField`, `messageBox.subjectField`), else from the first of `body`, `content`, `text` (and `subject`, `title`) that holds text. A message with no text SHALL NOT be sent: portaliq SHALL record the attempt as failed with refusal code `empty_body`.

#### Scenario: A decision goes both ways
- **GIVEN** an organisation offering the channel and a case app returning a recipient for a decision letter
- **WHEN** the letter arrives in the resident's portal inbox
- **THEN** a send is requested from integriq and the notification log records it as sent, with integriq's message id
- @e2e exclude Needs integriq and a declaring case app; pinned by MessageBoxDispatchJobTest::testMessageIdIsRecorded and PortalRecordChangeListenerTest::testMessageBoxJobOnlyWhenAllowed

#### Scenario: The letter carries the text the case app keeps
- **GIVEN** a case app whose message keeps its text in `content` (dossiq's portaalBericht)
- **WHEN** the letter is sent to the message box
- **THEN** integriq receives that text as the letter's body, and its subject
- @e2e exclude Needs integriq and a declaring case app; pinned by MessageBoxDispatchJobTest::testDossiqsPortaalBerichtTextIsTheLetter and ::testTheDeclaredLetterFieldsAreRead

#### Scenario: An empty letter is never sent
- **GIVEN** a message with no text in any of the letter fields
- **WHEN** it would be sent to the message box
- **THEN** nothing is asked of integriq, the attempt is recorded as failed with `empty_body`, and a warning names the message, not the resident
- @e2e exclude A negative over events and rows; pinned by MessageBoxDispatchJobTest::testAnEmptyLetterIsNotSent

#### Scenario: Integriq is not installed
- **GIVEN** an instance without integriq
- **WHEN** a message for the channel arrives
- **THEN** the attempt is recorded as failed and the resident sees no delivery line
- @e2e exclude Integriq's absence cannot be staged on the shared instance; pinned by MessageBoxDispatchJobTest::testUnhandledEventIsARefusal

### Requirement: The resident sees only a real delivery (REQ-MBC-004)

Portaliq SHALL update the attempt when integriq reports a status for a message portaliq requested. A simulated delivery SHALL be recorded as simulated and SHALL NOT be shown as delivered. The inbox SHALL show "Also sent to {label}." under a message only once its message box send was delivered or read.

#### Scenario: A delivered letter
- **GIVEN** a message box send for a resident's message
- **WHEN** integriq reports it delivered
- **THEN** the resident's inbox shows "Also sent to {label}." under that message
- @e2e exclude Needs integriq's live binding; pinned by PortalInboxReaderTest::testOnlyADeliveredMessageBoxSendIsShown and tests/message-box-channel.spec.mjs

#### Scenario: A simulated send stays quiet
- **GIVEN** an integriq instance whose digital post binding is simulated
- **WHEN** it reports the send
- **THEN** the attempt is recorded as simulated and the inbox shows no delivery line
- @e2e exclude Needs integriq's simulated binding; pinned by PortalDigitalPostDeliveredListenerTest::testSimulatedStaysSimulated and MessageBoxDispatchJobTest::testAStatusAnnouncedDuringTheSendLandsOnTheRow

### Requirement: The resident can switch the channel off (REQ-MBC-005)

When the organisation offers the channel, the notification settings SHALL show "Also send letters to {label}", on by default. When the resident switches it off, portaliq SHALL request no message box send for them.

#### Scenario: A resident prefers the portal only
- **GIVEN** a resident who switched the message box choice off
- **WHEN** a decision letter arrives in their inbox
- **THEN** no message box send is requested

## ADDED Requirements

### Requirement: A sender can ask for a read receipt on a message (REQ-IRR-001)

A `portalMessage` MAY carry `readReceiptRequested` (boolean, default false), `readAt` (date-time) and `sendingRef` (string). The writer of a message SHALL set `readReceiptRequested` and `sendingRef`. Mark-read SHALL NOT change `readReceiptRequested` or `sendingRef`, and nothing in the mark-read request body SHALL be written.

#### Scenario: A message asks for a receipt
- **GIVEN** an app that writes a `portalMessage` with `readReceiptRequested: true` and `sendingRef: "brief-groep-5b-2026-10-07"`
- **WHEN** the resident opens their inbox
- **THEN** the message is listed with both values unchanged
- @e2e exclude pinned by PHPUnit `PortalInboxReaderTest::testReceiptFieldsReachTheInboxRow`; no live sending app writes them yet

#### Scenario: The resident cannot withdraw the request
- **GIVEN** a message with `readReceiptRequested: true`
- **WHEN** the resident sends mark-read with `{readReceiptRequested: false}` in the body
- **THEN** the flag stays true and only the read fields are written
- @e2e exclude write-authorization invariant pinned by `ContributionControllerTest::testMarkReadNeverWritesTheReceiptRequest`; no UI surface

### Requirement: The read moment is recorded once, and only when asked (REQ-IRR-002)

Mark-read on a message with `readReceiptRequested: true` and no `readAt` SHALL write `read: true` and the current time into `readAt`. When `readAt` already holds a value, mark-read SHALL keep it. On a message without a request, mark-read SHALL write `read: true` only and MUST NOT write `readAt`. On an app's inbox collection that names `readAt` in `messageFields`, mark-read SHALL write the current time only when that field is empty.

#### Scenario: The first open is the moment
- **GIVEN** a message with a receipt request and no `readAt`
- **WHEN** the resident marks it read at 09.14 and again at 11.02
- **THEN** `readAt` holds 09.14
- @e2e exclude idempotency invariant pinned by `ContributionControllerTest::testMarkReadKeepsTheFirstReadMoment`

#### Scenario: No request, no moment
- **GIVEN** a message without a receipt request
- **WHEN** the resident marks it read
- **THEN** `read` is true and `readAt` stays empty
- @e2e exclude pinned by `InboxMessageFieldsTest::testNoRequestWritesNoMoment`

### Requirement: The resident sees that a receipt was asked (REQ-IRR-003)

On the Berichten page a message with a receipt request SHALL show the line "De afzender ziet wanneer u dit bericht hebt geopend." (English: "The sender sees when you opened this message."). A message without a request MUST NOT show it. On an app's inbox, the line SHALL show when the collection names `readReceiptRequested` in `messageFields` and that field is true.

#### Scenario: A parent sees the notice before opening
- **GIVEN** a parent with a class letter that asks for a receipt
- **WHEN** she opens Berichten
- **THEN** the letter's card shows "De afzender ziet wanneer u dit bericht hebt geopend."
- @e2e exclude pinned by the node test in `tests/inbox-read-receipt.spec.mjs`; the live check is in the build PR

### Requirement: Staff see when a message was read (REQ-IRR-004)

On the Portal Message page the "Gelezen" row SHALL show "nog niet" while the message is unread, and the `readAt` moment once it is set. A message with a receipt request SHALL show a row "Leesbevestiging" with "gevraagd", and its history SHALL list "Gelezen door {ontvanger}" at the `readAt` moment. A message without a request SHALL keep today's read badge and show no moment.

#### Scenario: A case handler sees the moment
- **GIVEN** a message to R. Mulder with a receipt request, read at 09.14
- **WHEN** a case handler opens the message page
- **THEN** "Gelezen" shows "vandaag, 09.14", "Leesbevestiging" shows "gevraagd", and the history has "Gelezen door R. Mulder"
- @e2e exclude manifest-rendered detail page, pinned by `tests/inbox-read-receipt.spec.mjs` reading the manifest; the live check is in the build PR

### Requirement: Staff see who read one sending (REQ-IRR-005)

Messages that share a `sendingRef` SHALL be one sending. The Portal Messages index SHALL accept a filter on `sendingRef` and show the columns "Ontvanger", "Gelezen" and "Gelezen op", with the count "{n} van {m} gelezen" above the table. The message page SHALL link to that filtered index from the "Ontvanger" row when the message has a `sendingRef`. Portaliq MUST NOT add an endpoint for this list: staff and sending apps read it through OpenRegister's object list, under OpenRegister RBAC.

#### Scenario: A teacher's letter to a class
- **GIVEN** 24 messages with `sendingRef: "brief-groep-5b-2026-10-07"`, 18 of them read
- **WHEN** a staff member follows "Alle ontvangers van deze verzending"
- **THEN** the index lists the 24 recipients, each with its read moment or "nog niet", under "18 van 24 gelezen"
- @e2e exclude manifest-rendered index, pinned by `tests/inbox-read-receipt.spec.mjs`; the live check is in the build PR

#### Scenario: Another organisation's sending stays hidden
- **GIVEN** a staff member of organisation A and a sending of organisation B
- **WHEN** she filters the index on B's `sendingRef`
- **THEN** the list is empty
- @e2e exclude OpenRegister RBAC and multitenancy, pinned in OpenRegister; portaliq adds no read path

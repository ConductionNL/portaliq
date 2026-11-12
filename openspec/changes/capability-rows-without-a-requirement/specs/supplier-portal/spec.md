## ADDED Requirements

### Requirement: Staff can browse the portal sessions that were issued (REQ-CRG-001)

The admin app SHALL list every `portalSession` with its subject, audience, trust level, issue and expiry time and whether it was revoked, and SHALL open one session's detail from the list. Only staff who may administer portaliq SHALL reach the list. The list SHALL NOT show a bearer token.

#### Scenario: A clerk checks a revoked session
- **GIVEN** a session for `client` at trust `substantial` that an administrator revoked
- **WHEN** a clerk opens Sessions in the admin app
- **THEN** the row shows the subject, `client`, `substantial`, its issue and expiry time and that it was revoked, and no token

### Requirement: Staff can browse and search every portal message (REQ-CRG-002)

The admin app SHALL list every `portalMessage` across all subjects, with the subject, whether it was read and when it was received, SHALL let staff search the list, and SHALL open one message's full record. A portal visitor SHALL NOT reach this list.

#### Scenario: A clerk finds a message sent to one resident
- **GIVEN** messages to several residents, one of them about case Z-2026-0042
- **WHEN** a clerk opens Messages and searches for Z-2026-0042
- **THEN** that message is listed with its subject, its read state and its received date, and opens to its full record

### Requirement: The staff dashboard counts unread portal messages (REQ-CRG-003)

The admin dashboard SHALL show a tile with the number of `portalMessage` objects that are not read, counted when the dashboard loads.

#### Scenario: Three messages wait
- **GIVEN** three portal messages that no resident has read, and one that was read
- **WHEN** a member of staff opens the dashboard
- **THEN** the unread messages tile reads 3

### Requirement: A submission receipt offers the answers as a PDF (REQ-CRG-005)

A receipt `portalMessage` written for a successful create SHALL offer a PDF that holds the reference, the moment of receipt and the submitted answers from its data copy, and nothing outside that copy. The PDF SHALL open only for the subject the receipt belongs to.

#### Scenario: A resident keeps a copy of her request
- **GIVEN** a resident who sent a Woo request and received a receipt with reference WMEBV-2026-0193
- **WHEN** she opens the receipt in her inbox and chooses the PDF
- **THEN** a PDF downloads with WMEBV-2026-0193, the moment of receipt and her answers

#### Scenario: Another resident asks for it
- **WHEN** another signed-in resident requests that receipt's PDF
- **THEN** the portal answers 404 and renders nothing

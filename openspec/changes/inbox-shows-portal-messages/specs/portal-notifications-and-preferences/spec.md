---
status: proposed
---

# Spec: portal-notifications-and-preferences

## Purpose

The notices portaliq writes itself reach the resident's portal inbox, not only
their e-mail.

## ADDED Requirements

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

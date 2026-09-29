---
status: proposed
---

# Spec: portal-task-delivery

## Purpose

A resident who missed the deadline for something the organisation asked
them for hears about it, in the portal inbox and by mail, in words that say
it is late. Requested by TenderNed 409958, portaliq matrix row
`dem-tnd-reminders-overdue`.

## ADDED Requirements

### Requirement: An overdue delivery reaches the resident as overdue (REQ-TRD-001)

The delivery job SHALL accept the delivery kind `overdue`. For a
`portal-inbox` row of that kind it SHALL write one `portalMessage` whose
subject reads "Your task is overdue: {title}" and whose body reads "This task
was due on {date}.", carrying the `taskUuid` deep link like every other
delivery.

#### Scenario: A resident misses the deadline for a payslip
- **GIVEN** a resident with an open task "Send your latest payslip" due yesterday, and an `overdue` delivery row for it on the `portal-inbox` channel
- **WHEN** the delivery job runs
- **THEN** the resident's inbox holds a message "Your task is overdue: Send your latest payslip" that opens the task in "Mijn taken"
- e2e: `tests/e2e/tasks-reminders-after-the-deadline.spec.ts`

#### Scenario: The body does not ask for the impossible
- **GIVEN** an `overdue` row whose message carries a `dueAt` in the past
- **WHEN** the job writes the inbox message
- **THEN** the body says "This task was due on {date}." and does not say "Please finish this task before {date}."
- @e2e exclude Body composition; pinned by PortalTaskDeliveryJobTest::testOverdueBodyStatesThePastDueDate

### Requirement: The mail says which kind of delivery it is (REQ-TRD-002)

The delivery job SHALL choose the mail subject and body by the row's kind:
the unchanged new-task wording for `ask` and `re-ask`, a reminder wording
for `reminder`, and an overdue wording for `overdue`. Every mail SHALL carry
only the organisation name and the portal link.

#### Scenario: An overdue mail does not announce a new task
- **GIVEN** an `overdue` delivery row on the `mail` channel for a resident with a valid address
- **WHEN** the job sends it
- **THEN** the subject reads "Your task in the portal of {organisation} is overdue" and the body carries no task title or case content
- @e2e exclude Mail content; pinned by PortalTaskDeliveryJobTest with the IMailer mock

#### Scenario: A reminder mail is a reminder
- **GIVEN** a `reminder` delivery row on the `mail` channel
- **WHEN** the job sends it
- **THEN** the subject reads "Reminder: you have an open task in the portal of {organisation}"
- @e2e exclude Mail content; pinned by PortalTaskDeliveryJobTest with the IMailer mock

### Requirement: An unknown kind fails honestly (REQ-TRD-003)

The delivery job SHALL mark a row with a kind it does not know as failed,
with the reason "unknown delivery kind: {kind}", and SHALL NOT write a
message or send a mail for it.

#### Scenario: A kind from a newer engine is not sent as a new task
- **GIVEN** a pending row of kind `summons` the job does not know
- **WHEN** the job runs
- **THEN** the row is marked failed with that reason and the resident receives nothing
- @e2e exclude Ledger settlement; pinned by PortalTaskDeliveryJobTest::testUnknownKindIsMarkedFailed

### Requirement: Portaliq keeps no reminder clock (REQ-TRD-004)

Portaliq SHALL send an overdue notice only for a ledger row the case app
recorded. It SHALL NOT compute that a task is overdue, and SHALL NOT repeat a
notice on its own schedule.

#### Scenario: No row, no notice
- **GIVEN** a task whose deadline passed and no `overdue` row in the ledger
- **WHEN** the delivery job runs
- **THEN** no message and no mail is sent for that task
- @e2e exclude Absence of a side effect; pinned by PortalTaskDeliveryJobTest

# portal-task-delivery Specification

## Purpose
TBD - created by archiving change portal-task-delivery. Update Purpose after archive.

## Requirements

### Requirement: The task proxy is the only path, and the assertion never reaches the browser

Portaliq MUST expose the portal task surface through its own backend endpoints
(`GET /portal/api/tasks`, `GET /portal/api/tasks/{uuid}`,
`POST /portal/api/tasks/{uuid}/complete`), each requiring a valid portal bearer
session (fail-closed 401 without one). The backend MUST mint the short-lived
HS256 `X-Portal-Subject` assertion server-side per forwarded request and MUST
NOT include it in any response. The browser MUST NOT call openregister
directly for tasks.

#### Scenario: An unauthenticated request sees nothing

- GIVEN no (or an invalid) portal bearer
- WHEN any of the three task endpoints is called
- THEN the answer is 401 with no task data, no assertion, and no forward to openregister

- @e2e exclude fail-closed auth contract — pinned by `tests/Unit/Controller/PortalTaskProxyControllerTest.php` (mutation check: the gateway mock asserts zero forwards); the proof rig for the wire is the co-installed docker env with openregister `feature/flow-portal-task`, which the repo's CI instance does not carry until #3282 merges

#### Scenario: The assertion is minted server-side and stays there

- GIVEN an authenticated subject listing their tasks
- WHEN the proxy forwards to `/apps/openregister/api/portal-tasks`
- THEN the forward carries `X-Portal-Subject` (iss `portaliq`, `use: assertion`) minted from the resolved subject, the client's own Authorization header is not forwarded, and the proxy's response body contains no assertion

- @e2e exclude server-to-server header contract, invisible to a browser test by design — pinned by `tests/Unit/Service/PortalTaskGatewayTest.php`

#### Scenario: Refusals reach the resident in plain language

- GIVEN openregister answers 404 `no-such-task`, 400 `upload-constraint`, or 409 `task-closed`
- WHEN the proxy relays the answer
- THEN the status and `code` pass through unchanged, and the SPA renders the matching Dutch B1 message
- AND a 401 `portal-subject-*` from openregister (portaliq's own assertion refused: a configuration defect) becomes 503 `task-service-unavailable`, and a transport failure becomes 502 `task-service-unreachable`

- @e2e exclude refusal mapping matrix — pinned by `tests/Unit/Controller/PortalTaskProxyControllerTest.php`; the resident-visible rendering needs the co-installed #3282 rig named above

### Requirement: "Mijn taken" lists, details and completes the party's open tasks

The public portal SPA MUST show an authenticated party a "Mijn taken" entry
(announced by `tasks: {enabled: true}` on the aggregated contributions
response; never on the anonymous aggregate), listing their open portal tasks
with title and due date, a detail view (title, description, due date, upload
rules), and a completion form honouring the task's frozen upload constraints
(`required`, `maxFiles`, accepted types, max size) with a comment field. A
completion posts multipart through the proxy; success shows a confirmation and
removes the task from the open list.

A CONFIRMED completion is a submission in the WMEBV sense (art. 2:10). Once the
seam answers 2xx the proxy MUST write a `portalAuditEntry` with verb `complete`
naming the task (`openregister` / `portalTask` / uuid, with the session `jti`;
a fact, never payload) and MUST produce the same ontvangstbevestiging a
create-action gets: a receipt `portalMessage` with a reference id in the
resident's inbox and a linked `portalSubmission` proof log (appId `portaliq`,
actionId `task.complete`) whose data copy carries the submitted answers,
comment, the recorded outcome, the task and the NAMES of the uploads — never
file content. A refused (4xx), unavailable (seam 401 → 503) or unreachable
(502) relay MUST record neither.

#### Scenario: The resident journey — see the task, upload, complete

- GIVEN a resident with a bearer session and one open portal task requiring an upload
- WHEN they open "Mijn taken", open the task, attach an accepted file and submit
- THEN the completion posts through `/portal/api/tasks/{uuid}/complete`, the confirmation shows, and the list no longer carries the task

- @e2e exclude the journey needs a seeded external task, which only openregister `feature/flow-portal-task` (#3282, unmerged) can create; proof rig: the nextcloud-docker-dev env with that branch co-installed, `jwt_signing_secret` set, and a flow run that parks on a portal task — `tests/e2e/portal-tasks.spec.ts` is the placeholder home once the branch merges

#### Scenario: The anonymous aggregate never announces tasks

- GIVEN the anonymous contribution aggregate (no bearer resolves)
- WHEN `GET /portal/api/contributions` answers
- THEN it carries no enabled tasks surface, and the SPA renders no "Mijn taken" entry

- @e2e exclude pinned by `tests/Unit/Controller/PortalTaskProxyControllerTest.php` (contributions announcement) — the anonymous SPA path renders from the same flag with no separate wire

#### Scenario: A completion is audited and acknowledged like a create-action

- GIVEN a resident with a bearer session who completes an open task through the proxy with a comment and an accepted upload
- WHEN the seam confirms the completion (2xx)
- THEN a `portalAuditEntry` with verb `complete` names the task and carries the session jti, a receipt `portalMessage` with a reference id lands in the resident's inbox, and a linked `portalSubmission` proof log records the answers, comment, outcome and upload names — and a 400/404/409, a seam 401 (503) or a transport failure (502) records none of these

- @e2e exclude pinned by `tests/Unit/Controller/PortalTaskProxyControllerTest.php` (`testASuccessfulCompletionIsAuditedAndReceipted`, `testTheCopyNamesOnlyTheEvidenceTheSeamStored`, `testTheSubmissionCopyFallsBackToTheRequestedOutcomeAndUuid`, `testAnEmptySeamUuidFallsBackToTheAddressedUuid`, `testARefusedOrFailedCompletionRecordsNothing`) and, for the receipt + proof log themselves, `tests/Unit/Service/SubmissionReceiptServiceTest.php` (`testATaskCompletionYieldsAReceiptAndALinkedProofLog`, real service over a stubbed writer); the end-to-end run needs the seeded flow rig named above (verified by hand on the dev instance, WOO-569)

#### Scenario: Upload constraints are enforced and named

- GIVEN a task whose constraints allow 1 PDF of at most 5 MB
- WHEN the resident attaches two files, or a 6 MB file, or a .exe
- THEN the form refuses client-side with the constraint named in Dutch, and a server 400 `upload-constraint` (defense in depth) renders the same way

- @e2e exclude constraint rendering is client logic over the same fixture shape the unit suite pins; the end-to-end refusal needs the #3282 rig named above

### Requirement: The delivery worker settles every ledger row, idempotently and in isolation

A recurring portaliq background job MUST drain
`PortalTaskDeliveryService::pending()` in-process when openregister is
co-installed (the REST admin surface stays the documented fallback for a split
deployment and is not consumed here). Per `portal-inbox` row it MUST write one
`portalMessage` into the party's own inbox scope carrying `taskUuid` and the
ledger row's uuid as `deliveryUuid`; per `mail` row it MUST send one
privacy-minimal mail (organisation name and portal link only, never task or
case content) to the party's own `portalAccount` address. Each row MUST be
settled with `markDelivered()` or `markFailed(reason)`. The job MUST be
idempotent per row (a message already carrying the row's `deliveryUuid` is
settled, not duplicated) and MUST isolate failures (a failing row is marked
failed with the reason and the remaining rows still run). Absent openregister,
the job MUST do nothing and log at debug.

#### Scenario: A crash between write and settle does not duplicate the message

- GIVEN a `portal-inbox` row whose message was written but never settled
- WHEN the worker runs again
- THEN it finds the existing message by `deliveryUuid`, writes no second message, and marks the row delivered

- @e2e exclude worker crash-replay is unreachable from a browser — pinned by `tests/Unit/BackgroundJob/PortalTaskDeliveryJobTest.php`

#### Scenario: One failing row never blocks the rest

- GIVEN three pending rows where the first row's write throws
- WHEN the worker runs
- THEN row one is marked failed with the reason, rows two and three are still processed and settled, and no exception reaches the cron runner

- @e2e exclude failure isolation is pinned by `tests/Unit/BackgroundJob/PortalTaskDeliveryJobTest.php`

#### Scenario: A mail row without a usable address is an honest failure

- GIVEN a `mail` row whose party has no `portalAccount`, or an account without a valid address
- WHEN the worker processes it
- THEN the row is marked failed naming the missing address, and the caseworker's delivery state reads `failed`, never silence

- @e2e exclude pinned by `tests/Unit/BackgroundJob/PortalTaskDeliveryJobTest.php`; the mail itself is asserted through the IMailer mock

### Requirement: The inbox message deep-links to the task

A `portalMessage` written by the delivery worker MUST carry the task's uuid,
and the unified inbox MUST render an action on such messages that opens the
task's detail on the "Mijn taken" surface.

#### Scenario: From the inbox message to the task detail

- GIVEN an inbox message carrying a `taskUuid`
- WHEN the resident activates "Bekijk taak"
- THEN the shell switches to "Mijn taken" with that task's detail open (or the plain not-found message when the task has since closed)

- @e2e exclude the in-SPA hand-off is state-only (no URL) and the fixture needs a worker-written message, which needs the #3282 rig; the wiring is pinned by the `deliveryUuid`/`taskUuid` schema declaration test and the worker unit suite

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

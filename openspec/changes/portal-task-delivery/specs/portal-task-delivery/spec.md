# portal-task-delivery — delta: portal-task-delivery

**OpenSpec change**: `portal-task-delivery` (new capability). Portaliq's half of
the resident task leg over openregister's `feature/flow-portal-task` seam
(openregister#3282): the "Mijn taken" portal surface, the backend proxy that
mints the `X-Portal-Subject` assertion server-side, and the delivery worker
that turns the delivery ledger into inbox messages and mails.

## ADDED Requirements

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

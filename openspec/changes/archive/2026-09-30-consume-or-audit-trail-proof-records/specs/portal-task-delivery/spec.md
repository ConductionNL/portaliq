## MODIFIED Requirements

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
seam answers 2xx the proxy MUST record a `portaliq.complete` row in OpenRegister's audit trail
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
- THEN a `portaliq.complete` audit-trail row names the task and carries the session jti, a receipt `portalMessage` with a reference id lands in the resident's inbox, and a linked `portalSubmission` proof log records the answers, comment, outcome and upload names — and a 400/404/409, a seam 401 (503) or a transport failure (502) records none of these

- @e2e exclude pinned by `tests/Unit/Controller/PortalTaskProxyControllerTest.php` (`testASuccessfulCompletionIsAuditedAndReceipted`, `testTheCopyNamesOnlyTheEvidenceTheSeamStored`, `testTheSubmissionCopyFallsBackToTheRequestedOutcomeAndUuid`, `testAnEmptySeamUuidFallsBackToTheAddressedUuid`, `testARefusedOrFailedCompletionRecordsNothing`) and, for the receipt + proof log themselves, `tests/Unit/Service/SubmissionReceiptServiceTest.php` (`testATaskCompletionYieldsAReceiptAndALinkedProofLog`, real service over a stubbed writer); the end-to-end run needs the seeded flow rig named above (verified by hand on the dev instance, WOO-569)

#### Scenario: Upload constraints are enforced and named

- GIVEN a task whose constraints allow 1 PDF of at most 5 MB
- WHEN the resident attaches two files, or a 6 MB file, or a .exe
- THEN the form refuses client-side with the constraint named in Dutch, and a server 400 `upload-constraint` (defense in depth) renders the same way

- @e2e exclude constraint rendering is client logic over the same fixture shape the unit suite pins; the end-to-end refusal needs the #3282 rig named above

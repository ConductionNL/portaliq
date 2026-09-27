# Design: portal-take-assessment

## Architecture Overview

```
portal SPA (TimedTaskView)                     portaliq server                          leaf app (learniq)
list:    POST /portal/api/actions/learniq/listTests   --> forward + X-Portal-Subject --> POST /apps/learniq/api/portal/assessments
start:   POST .../actions/learniq/startTest           --> forward (+ learnerRef stamp) --> .../assessments/start
answer:  POST .../actions/learniq/saveTestAnswer      --> forward (+ learnerRef stamp) --> .../assessments/answer
submit:  POST .../actions/learniq/submitTest          --> forward (+ learnerRef stamp) --> .../assessments/submit
result:  POST .../actions/learniq/readTestResult      --> forward (+ learnerRef stamp) --> .../assessments/result
attempts: GET /portal/api/collections/learniq/assessment-result (scoped by learnerRef)
```

Portaliq holds no test data and makes no test decision. The forward it already
has (contract v2, A6) checks the action is in the subject's own trust-filtered
manifest, re-checks `minTrust`, refuses non-local endpoints, rebuilds the body
from the action's `fields` whitelist, signs a 60 second `X-Portal-Subject`
assertion and relays status and body. This change adds the block that ties five
such actions to one screen, and a server stamp of the learner's reference.

## Decisions

### D1: Forward to the leaf app instead of writing attempts

Writing `AssessmentResult` from portaliq (with RBAC off, as the object writer
does) would bypass learniq's rules: its attempt gate and integrity listener
exempt a write with no Nextcloud user as system context, and a portal request
has none. Forwarding keeps the window, access code, deadline, immutability and
scoring where they are implemented and tested.

### D2: A collection kind, not a new block type

`kind` already routes `inbox` collections to their own surface. A `timedTask`
collection is also a real, scoped list (the attempts), so the default page
synthesis, the collection read and the field projection all keep working. A
block that fails validation degrades to that list instead of vanishing.

### D3: The learner's reference is stamped by the server

The assertion's claim set is frozen (`sub`, `audience`, `organisation`,
`trust`, `jti`, ...); it carries no `learnerRef`. Learniq scopes everything by
`learnerRef`, which portaliq resolves from the subject's own portal account
(`scopeClaim`). `subjectField` stamps that resolved value into the forwarded
body, overriding the client. It is opt-in by a new key, so no existing endpoint
action changes its body.

### D4: The deadline is the server's and includes extra time

The portal never computes a time limit. `start` returns `serverNow` and
`deadlineAt`; learniq computes the deadline from `startedAt`,
`timeLimitMinutes` and an active `ExamAccommodation` (`extra-time-percentage`).
The portal counts down from `deadlineAt - serverNow`, anchored to the client
clock at the moment the payload arrived, so a wrong client clock does not
shift the time left.

### D5: Items arrive parsed, never as QTI and never with answers

The leaf app sends each item as `{itemId, type, prompt, points, choices?,
sources?, targets?}` in presentation order (its drawn snapshot and option
order). No `correctResponse`, no raw QTI: the portal does not parse XML from
another app, and nothing a pupil's browser receives can reveal an answer.

### D6: Autosave per question, sequential, latest value wins

Each change queues the question's latest value; a queue sends one request at a
time, drops superseded values, and marks a question unsaved until its latest
value is acknowledged. Navigation and submit flush the queue first.

### D7: The logic is a pure module

`src/portal/lib/timedTask.js` holds the clock, the payload sanitiser, the
response shapes and the save queue, so `node --test` can pin them without a
browser; the React view only wires them.

## The contribution contract

This is the contract a leaf app implements. Learniq is the first consumer.

### Manifest (leaf app)

```php
// collections
[
    'id'          => 'studentTests',
    'kind'        => 'timedTask',
    'label'       => 'Tests',
    'register'    => 'learniq',
    'schema'      => 'assessment-result',
    'scopeField'  => 'learnerRef',
    'scopeClaim'  => 'learnerRef',
    'listable'    => true,
    'fields'      => ['assessmentId', 'assessmentTitle', 'lifecycle', 'startedAt', 'submittedAt', 'resultReleased'],
    'timedTask'   => [
        'available' => 'listTests',
        'start'     => 'startTest',
        'answer'    => 'saveTestAnswer',
        'submit'    => 'submitTest',
        'result'    => 'readTestResult',
    ],
],
// actions: all POST, all instance-local, each stamped with the learner
['id' => 'listTests',      'endpoint' => '/apps/learniq/api/portal/assessments',        'method' => 'POST', 'fields' => [],                               'subjectField' => 'learnerRef', 'scopeClaim' => 'learnerRef'],
['id' => 'startTest',      'endpoint' => '/apps/learniq/api/portal/assessments/start',  'method' => 'POST', 'fields' => ['taskId', 'accessCode'],         'subjectField' => 'learnerRef', 'scopeClaim' => 'learnerRef'],
['id' => 'saveTestAnswer', 'endpoint' => '/apps/learniq/api/portal/assessments/answer', 'method' => 'POST', 'fields' => ['attemptId', 'itemId', 'response'], 'subjectField' => 'learnerRef', 'scopeClaim' => 'learnerRef'],
['id' => 'submitTest',     'endpoint' => '/apps/learniq/api/portal/assessments/submit', 'method' => 'POST', 'fields' => ['attemptId'],                    'subjectField' => 'learnerRef', 'scopeClaim' => 'learnerRef'],
['id' => 'readTestResult', 'endpoint' => '/apps/learniq/api/portal/assessments/result', 'method' => 'POST', 'fields' => ['attemptId'],                    'subjectField' => 'learnerRef', 'scopeClaim' => 'learnerRef'],
```

`minTrust` on each action as the school requires (`low` for a pupil account).
The collection uses a scalar scope field: portaliq's direct scope compares one
value, so an array field such as `learnerRefs` does not match (see the finding
in the PR body).

### Payloads (portal SPA <-> leaf app, relayed unchanged by portaliq)

Every request body is JSON built from the action's `fields` plus the stamped
`learnerRef`. Every response is JSON; an error carries `error` and may carry a
`message` in the pupil's language, which the portal shows as is.

**`available`** -> 200
```json
{ "tasks": [ {
    "taskId": "<assessment-uuid>", "title": "Toets hoofdstuk 3", "description": "…",
    "timeLimitMinutes": 30, "extraTimeMinutes": 7.5, "availableUntil": "2026-10-01T12:00:00+02:00",
    "needsAccessCode": true, "state": "available" | "in-progress", "attemptId": "<attempt-uuid, when in-progress>"
} ] }
```

**`start`** body `{taskId, accessCode?}` -> 200 (a new or the resumed attempt)
```json
{ "attemptId": "<attempt-uuid>", "title": "Toets hoofdstuk 3",
  "serverNow": "2026-10-01T09:00:00+02:00", "deadlineAt": "2026-10-01T09:37:30+02:00",
  "items": [
    { "itemId": "<item-uuid>", "type": "choice",       "prompt": "…", "points": 1, "choices": [ {"id": "A", "label": "…"} ] },
    { "itemId": "<item-uuid>", "type": "inlineChoice", "prompt": "…", "points": 1, "choices": [ … ] },
    { "itemId": "<item-uuid>", "type": "textEntry",    "prompt": "…", "points": 1 },
    { "itemId": "<item-uuid>", "type": "extendedText", "prompt": "…", "points": 4 },
    { "itemId": "<item-uuid>", "type": "order",        "prompt": "…", "points": 2, "choices": [ … ] },
    { "itemId": "<item-uuid>", "type": "match",        "prompt": "…", "points": 3, "sources": [ … ], "targets": [ … ] },
    { "itemId": "<item-uuid>", "type": "hotspot",      "prompt": "…", "points": 1 }
  ],
  "responses": { "<item-uuid>": "A" } }
```
`deadlineAt` is null for an untimed test. Errors: 403 `not_available`
(window, release conditions, attempts used), 403 `access_code_required` or
`access_code_wrong`.

**`answer`** body `{attemptId, itemId, response}` -> 200 `{ "saved": true }`;
409 `attempt_closed` after submit or after the deadline.

| type | response shape |
|---|---|
| choice, inlineChoice | option id (string) |
| textEntry, extendedText, any other type | text (string) |
| order | option ids in the pupil's order (string[]) |
| match | object from source id to target id |

**`submit`** body `{attemptId}` -> 200 `{ "state": "submitted" }`; 409
`attempt_closed` when already submitted.

**`result`** body `{attemptId}` -> 200 `{ "released": false }` or
```json
{ "released": true, "score": 14, "maxScore": 18, "passed": true, "feedback": "…",
  "items": [ { "itemId": "<item-uuid>", "prompt": "…", "response": "A", "score": 1, "maxScore": 1 } ] }
```

## What learniq adds (its follow-up change)

1. `AssessmentResult.learnerRef` (scalar LearnerProfile UUID), stamped on
   every create, for the attempt collection's scope.
2. A portal assessment controller behind the five routes, verifying
   `X-Portal-Subject` the way filinq's and shillinq's `PortalAssertionVerifier`
   do, and taking the learner from the stamped `learnerRef`, never from the
   rest of the body.
3. On that path, the rules its listeners skip for a user-less write: the
   availability window and access code (`AssessmentAttemptPolicy`), the
   single in-progress attempt and `maxAttempts`, answers only on the
   learner's own `in-progress` attempt, nothing after submit.
4. `deadlineAt = startedAt + timeLimitMinutes * (1 + extra-time-percentage /
   100)` from an approved, active `ExamAccommodation` (assessment-specific
   before generic); answers refused after `deadlineAt` plus a short grace, and
   the attempt submitted on the first request after it.
5. Items parsed from QTI server-side into the shapes above, in the drawn
   order, with no `correctResponse`.
6. A release rule for `result` (graded and released by the teacher), and
   `learnerId` resolved from the LearnerProfile for the existing scoring,
   grading and GradeEntry flow.
7. The collection and five actions in `studentContribution()`.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Why |
|---|---|---|
| Timed-task block validation | imperative normaliser | Manifest sanitising, like every other contribution key |
| `subjectField` stamp | imperative, in the forward | A security stamp on an outbound call; no schema involved |
| Attempt lifecycle | stays in learniq (`x-openregister-lifecycle` on AssessmentResult) | Not portaliq's |

## Nextcloud Integration

- Controllers: `ContributionController::action()` (existing route) gains the
  stamp. No new route.
- Services: `PortalObjectReader::resolveScopeValue()` (existing),
  `PortalActionForwarder` (existing).

## Security Considerations

- Learner identity: stamped by the server from the subject's own account; a
  client value is overwritten; an unresolvable claim is 403 with no forward.
- Authorisation: unchanged forward checks (own manifest, `minTrust`, SSRF
  guard, `fields` whitelist).
- No answers leave the leaf app before release: D5.
- Time: the countdown is presentation; enforcement is learniq's (D4).
- Assertions stay non-replayable as sessions (existing requirement).

## NL Design System

Native form controls inside the existing `portaliq-form`/`portaliq-field`
wrappers, radio groups in a `fieldset` with a `legend`, a live region for the
countdown at minute steps and for save state, and buttons for move up/down so
ordering works with a keyboard.

## File Structure

```
lib/Contribution/TimedTaskConfigNormaliser.php      new
lib/Contribution/PortalManifestNormaliser.php       resolves timed tasks after actions
lib/Contribution/ActionConfigNormaliser.php         drops an action with a malformed subjectField
lib/Controller/ContributionController.php           subjectField stamp in action()
src/portal/lib/timedTask.js                         new, pure
src/portal/components/TimedTaskView.jsx             new
src/portal/components/TimedTaskItem.jsx             new
src/portal/components/PageView.jsx                  routes kind timedTask
src/portal/lib/portalApi.js                         forwardAction()
src/portal/i18n/en.json, nl.json
tests/Unit/Contribution/TimedTaskConfigNormaliserTest.php
tests/Unit/Controller/ContributionControllerSubjectFieldTest.php
tests/timed-task.spec.mjs
docs/operations/timed-tasks-in-the-portal.md
```

## Seed Data

Not applicable: no schema changes. Portaliq's example provider declares no
timed task; learniq's change carries the first declaration and its demo data.

## Trade-offs

- Five round trips per attempt shape, relayed through portaliq, instead of the
  SPA calling learniq directly: the price of one auth edge and one set of
  forward checks. Answers are small.
- Autosave on change sends more requests than save-on-next; the rate limit on
  the forward (60 per minute) allows a pupil answering once a second.

---
title: Timed tasks in the portal
sidebar_label: Timed tasks
---

# Timed tasks in the portal

A pupil opens the portal, starts a test, answers the questions against a
clock and hands it in. The school's own app keeps every rule: when the test
is open, the access code, the extra time, what may still change, the score.
Portaliq shows the questions, carries the answers and counts down to the
deadline the app gives it.

This page is the contract a leaf app implements.

## What the leaf app declares

A collection with `kind: timedTask` holds the pupil's attempts, and its
`timedTask` block names five endpoint actions of the same contribution:

```php
[
    'id'         => 'studentTests',
    'kind'       => 'timedTask',
    'label'      => 'Tests',
    'register'   => 'learniq',
    'schema'     => 'assessment-result',
    'scopeField' => 'learnerRef',
    'scopeClaim' => 'learnerRef',
    'listable'   => true,
    'fields'     => ['assessmentId', 'assessmentTitle', 'lifecycle', 'startedAt', 'submittedAt'],
    'timedTask'  => [
        'available' => 'listTests',
        'start'     => 'startTest',
        'answer'    => 'saveTestAnswer',
        'submit'    => 'submitTest',
        'result'    => 'readTestResult',
    ],
],
```

Each action is a POST to an endpoint in your own app, with a `fields`
whitelist and `subjectField`:

```php
['id' => 'saveTestAnswer', 'endpoint' => '/apps/learniq/api/portal/assessments/answer',
 'method' => 'POST', 'fields' => ['attemptId', 'itemId', 'response'],
 'subjectField' => 'learnerRef', 'scopeClaim' => 'learnerRef'],
```

`subjectField` makes portaliq put the pupil's own `learnerRef` in the body,
resolved from their account, over anything the browser sent. A block that
names a missing action, or an action without an endpoint, is ignored and the
collection shows as a plain list.

## What each endpoint answers

| Step | Body | Answer |
| --- | --- | --- |
| available | `learnerRef` | `{tasks: [{taskId, title, timeLimitMinutes, extraTimeMinutes, needsAccessCode, state, attemptId}]}` |
| start | `taskId`, `accessCode`, `learnerRef` | the attempt: `attemptId`, `title`, `serverNow`, `deadlineAt`, `items[]`, `responses{}` |
| answer | `attemptId`, `itemId`, `response`, `learnerRef` | `{saved: true}`, or 409 `attempt_closed` |
| submit | `attemptId`, `learnerRef` | `{state: "submitted"}` |
| result | `attemptId`, `learnerRef` | `{released: false}` or the score, the answers and any feedback |

Items arrive as `{itemId, type, prompt, points, choices, sources, targets}`,
already in presentation order, with plain-text prompts and never the correct
answer. The portal renders `choice`, `inlineChoice`, `textEntry`,
`extendedText`, `order` and `match`; any other type gets a text answer.

An error may carry a `message`. The portal shows it to the pupil as it is, so
write it in their language.

## What your app must enforce

The portal's countdown is a display. Your endpoints decide:

- whether the test is open, and the access code;
- the deadline, extra time included, and refusing answers after it;
- that an attempt belongs to the stamped learner, and answers only while it
  is in progress;
- when a result is released.

A write that arrives through your endpoint has no Nextcloud user. Listeners
that skip checks for "system context" skip them for these requests too, so run
the checks in the endpoint.

Next: add the collection and the five actions to your student contribution,
and answer them from a controller that verifies `X-Portal-Subject`.

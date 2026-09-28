# Contract: portal-take-assessment

The full payload contract, with examples, is in `design.md` under "The
contribution contract". This file lists the interface points and their errors.

## Consumers

- `learniq`: declares a `timedTask` collection and five endpoint actions in its
  student contribution and implements the five endpoints (follow-up change).

## Endpoints

### Portal side (existing route, new behaviour)

`POST /apps/portaliq/portal/api/actions/{appId}/{actionId}`: for an action that
declares `subjectField`, the forwarded body is the `fields` whitelist plus
`subjectField: <resolved scope value>`.

| Code | Condition |
|---|---|
| 401 | No portal session |
| 403 | Action not in the subject's manifest, `minTrust` unmet, non-local endpoint, or `subjectField` scope unresolvable |
| 502 `forward_failed` | Transport failure |
| any other | Relayed from the leaf app |

### Leaf app side (implemented by learniq)

| Action | Path (learniq) | Body | Success | Errors |
|---|---|---|---|---|
| available | `POST /apps/learniq/api/portal/assessments` | `learnerRef` | 200 `{tasks[]}` | 403 |
| start | `POST /apps/learniq/api/portal/assessments/start` | `taskId`, `accessCode?`, `learnerRef` | 200 attempt | 403 `not_available` / `access_code_required` / `access_code_wrong` |
| answer | `POST /apps/learniq/api/portal/assessments/answer` | `attemptId`, `itemId`, `response`, `learnerRef` | 200 `{saved}` | 404, 409 `attempt_closed` |
| submit | `POST /apps/learniq/api/portal/assessments/submit` | `attemptId`, `learnerRef` | 200 `{state}` | 404, 409 `attempt_closed` |
| result | `POST /apps/learniq/api/portal/assessments/result` | `attemptId`, `learnerRef` | 200 `{released, ...}` | 404 |

Every leaf endpoint verifies `X-Portal-Subject` and answers 404 for an attempt
that is not the stamped learner's.

## Error Codes

| Code | Meaning | Condition |
|---|---|---|
| 401 | Unauthenticated | No portal session (portaliq) |
| 403 | Forbidden | Forward refused (portaliq) or task not startable (leaf) |
| 404 | Not found | Attempt not the learner's (leaf) |
| 409 | Conflict | Attempt submitted or past its deadline (leaf) |
| 502 | Bad gateway | Leaf app unreachable (portaliq) |

## Versioning

Additive to contribution manifest v3: a new collection `kind` and a new action
key. Unknown to older leaf apps; ignored where not declared.

## Breaking Change Policy

Renaming a `timedTask` key, a payload field or a response shape breaks every
leaf app that declares one; such a change lists them as consumers and lands
after their update.

## SLA

Synchronous forward, 60 requests per minute per client on the forward route.

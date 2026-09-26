---
kind: code
---

# Proposal: parent-polls

Learniq round 1 competitor sweep, finding 9.9 "Polls and questionnaires to
parents" (`learniq-round1/compare/findings.md` / `change-plan.md` in
ConductionNL/market-intelligence, 2026-09-25). parentcom, moodle
(`mod/choice`), social-schools, kwieb and hoy all offer a parent-facing
poll or questionnaire. portaliq's nearest hit,
`src/manifest.d/progress.json#EvaluationCampaignDetail`, is a course
evaluation to LEARNERS — adjacent, does not close this row.

## Summary

A staff member creates a poll (a question with fixed options) addressed to
one audience within one organisation. Every portal subject of that
audience sees it on their portal, answers once, and can change their
answer while the poll is open. Portaliq owns the whole thing: the
schema, the create route, and the two subject-facing routes.

## Motivation

`change-plan.md`'s description names "per group" targeting, matching
`leaf-integrations`' PollsProvider's own per-Session/Cohort scoping. That
finer scoping needs a `via`-style join to group membership — the SAME
mechanism `portal-contribution-contract`'s "One-hop via join scoping"
already gives a CONTRIBUTED collection from another app, but that
mechanism does not exist for a schema portaliq owns itself. Generalising
it is real work with its own design questions (which app's group model,
whose membership is authoritative) that this NICE-priority, S-sized row
does not need answered to deliver its core value: a parent audience CAN
now be asked a question and answer it, addressed by the audience/
organisation scoping every other portalAccount-based feature in this app
already uses. Per-group targeting is named explicitly as a follow-up, not
silently dropped.

## Affected Projects

- [x] Project: `portaliq` — new schema, new service/controller, three
  new routes; no other project's code changes.

## Scope

### In Scope

- `portalPoll` schema: `question`, `options` (array of `{id, label}`),
  `audience` (default `parent`), `organisation`, `closesAt`, `createdBy`,
  `createdAt`.
- `portalPollResponse` schema: `pollId`, `subjectRef`, `optionId`,
  `respondedAt`.
- `PollService`: `create()` (staff), `forSubject()` (every open-or-answered
  poll for the subject's own `audience`+`organisation`, each annotated
  with the subject's own response if one exists), `respond()` (idempotent
  — a second call from the same subject on the same poll UPDATES their
  existing response rather than creating a duplicate).
- `PollController`: `POST /apps/portaliq/api/polls` (staff, `NoAdminRequired`,
  mirrors `ProposalController::proposeAsColleague`'s posture),
  `GET /portal/api/polls` (bearer-scoped), `POST /portal/api/polls/{id}/respond`
  (bearer-scoped).
- A closed poll (past `closesAt`) still lists (with its results visible to
  the subject who already answered) but refuses a new or changed response.

### Out of Scope

- Per-group targeting (see Motivation). This ships audience+organisation
  scoping only.
- Any portal SPA rendering. This change is API-only, matching
  `notification-preferences-per-role`'s precedent in this same lane — a
  poll-taking UI is a natural, separately reviewable follow-up.
- Aggregate results visible to STAFF (a tally, a CSV export). `create()`
  and the subject-facing routes are this change's whole scope; a results
  view is a follow-up once there is a real poll to look at results for.
- `leaf-integrations`' in-course learner `PollsProvider` — a different
  audience, a different data model, explicitly not touched.

## Approach

A new, small, self-contained schema pair plus one service/controller
following `ProposalService`/`ProposalController`'s exact conventions
(constructor DI over `PortalObjectReader`/`PortalObjectWriter`, fail-closed
guard clauses, `@spec` tags). Full detail in design.md.

## New Dependencies

None.

## Impact

- `lib/Settings/portaliq_register.json` (`portalPoll`, `portalPollResponse`)
- `lib/Service/PollService.php` (new)
- `lib/Controller/PollController.php` (new)
- `appinfo/routes.php` (three new routes)
- Unit tests for the service and controller.

## Cross-Project Dependencies

None.

## Risks

### Risk 1: A subject reads or changes another subject's response
**Severity:** Medium — **Mitigation:** `respond()` resolves the existing
response by `(pollId, subjectRef)` from the BEARER's own subjectRef, never
a client-supplied one; `forSubject()`'s per-poll response annotation is
looked up the same way. A unit test pins that one subject's response is
never returned to, or overwritten by, another.

### Risk 2: A poll addressed to the wrong organisation leaks across tenants
**Severity:** Low — **Mitigation:** `forSubject()` filters by the subject's
own `organisation` exactly as every other portal-facing read in this app
already does; a unit test seeds two organisations and asserts no
cross-tenant leak.

## Rollback Strategy

Revert the commit. New schema + new files only; no existing route, schema
or behaviour changes.

## Open Questions

None — per-group targeting is the one deliberate scope cut, recorded
above rather than left implicit.

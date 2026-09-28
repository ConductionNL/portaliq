---
status: proposed
---

# Spec: parent-polls

**Status:** proposed
**Scope:** portaliq
**Depends on:** none

## Purpose

A staff member asks a question, addressed to one audience within one
organisation. Every portal subject of that audience answers once, on
their own portal. Requested by the learniq competitor sweep, finding 9.9.

## ADDED Requirements

### Requirement: A poll is created by staff, addressed to one audience and organisation

Portaliq SHALL declare a `portalPoll` schema (`question`, `options[]` each
`{id, label}`, `audience`, `organisation`, `closesAt`, `createdBy`,
`createdAt`) and a `portalPollResponse` schema (`pollId`, `subjectRef`,
`optionId`, `respondedAt`). `POST /apps/portaliq/api/polls` SHALL create a
poll as the calling staff user (`NoAdminRequired`); a poll naming fewer
than two options SHALL be refused.

#### Scenario: A staff member creates a poll
- **GIVEN** a logged-in staff user
- **WHEN** they call `POST /apps/portaliq/api/polls` with a question and two options
- **THEN** a `portalPoll` exists with `createdBy` the calling user's uid
- @e2e exclude backend create path — covered by PHPUnit on `PollService::create()`; no UI surface in this change

#### Scenario: A poll with one option is refused
- **GIVEN** a logged-in staff user
- **WHEN** they call `POST /apps/portaliq/api/polls` with only one option
- **THEN** the request is refused and no poll is created
- @e2e exclude validation guard — covered by PHPUnit on `PollService::create()`

### Requirement: A portal subject sees only polls for their own audience and organisation

`GET /portal/api/polls` SHALL return every `portalPoll` whose `audience`
equals the bearer's own resolved audience AND whose `organisation` equals
the bearer's own resolved organisation — never a client-supplied value for
either. Each returned poll SHALL carry the subject's own response
(`optionId`), when one exists, and no other subject's.

#### Scenario: A subject sees polls for their own audience and organisation only
- **GIVEN** polls for audience `parent`/org `gemeente-x`, audience `parent`/org `gemeente-y`, and audience `teacher`/org `gemeente-x`
- **WHEN** a bearer resolving to `audience: parent, organisation: gemeente-x` calls `GET /portal/api/polls`
- **THEN** only the first poll is returned
- @e2e exclude backend filtering contract — covered by PHPUnit on `PollService::forSubject()`; no UI surface in this change

#### Scenario: A returned poll carries only the subject's own response
- **GIVEN** a poll two different subjects have both answered
- **WHEN** one of them calls `GET /portal/api/polls`
- **THEN** the poll's `myResponse` names only their own chosen option, never the other subject's
- @e2e exclude scoped annotation — covered by PHPUnit on `PollService::forSubject()`

### Requirement: A subject may answer once and change their answer while the poll is open

`POST /portal/api/polls/{id}/respond` SHALL create the subject's
`portalPollResponse` on a first call and UPDATE the SAME row (never a
second row) on a later call from the same subject on the same poll. A
call naming an `optionId` the poll does not declare SHALL be refused. A
call after the poll's `closesAt` SHALL be refused without changing the
existing response, if any.

#### Scenario: A second response updates, not duplicates
- **GIVEN** a subject who already answered a poll with option A
- **WHEN** they call `respond` again with option B
- **THEN** exactly one `portalPollResponse` exists for that (poll, subject) pair, now naming option B
- @e2e exclude idempotent-update contract — covered by PHPUnit on `PollService::respond()`; no UI surface in this change

#### Scenario: An option the poll does not declare is refused
- **GIVEN** a poll declaring options `a` and `b`
- **WHEN** a subject calls `respond` with `optionId: c`
- **THEN** the request is refused and no response is written
- @e2e exclude server-side allow-list — covered by PHPUnit on `PollService::respond()`

#### Scenario: A closed poll refuses a new response
- **GIVEN** a poll whose `closesAt` is in the past
- **WHEN** a subject who never answered calls `respond`
- **THEN** the request is refused and no response is written
- @e2e exclude closed-poll guard — covered by PHPUnit on `PollService::respond()`

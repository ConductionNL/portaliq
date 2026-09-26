# Design: parent-polls

## Architecture Overview

A new, small vertical slice, deliberately shaped like `ProposalService`/
`ProposalController` (change-proposal-queue): one service over
`PortalObjectReader`/`PortalObjectWriter`, one controller with a staff
route and two bearer-gated portal routes, no new abstraction.

## API Design

### `POST /apps/portaliq/api/polls` (staff, `NoAdminRequired`)
**Request:**
```json
{
  "question": "Welke datum past het beste voor de ouderavond?",
  "options": [{"id": "a", "label": "Dinsdag 6 oktober"}, {"id": "b", "label": "Donderdag 8 oktober"}],
  "audience": "parent",
  "organisation": "gemeente-x",
  "closesAt": "2026-10-01T00:00:00+00:00"
}
```
**Response:** the created `portalPoll`, or `{"error": "..."}` (422 for
fewer than two options).

### `GET /portal/api/polls` (bearer-scoped)
**Response:**
```json
{
  "polls": [
    {"id": "...", "question": "...", "options": [...], "closesAt": "...", "myResponse": "a"}
  ]
}
```
`myResponse` is `null` when the bearer has not answered yet.

### `POST /portal/api/polls/{id}/respond` (bearer-scoped)
**Request:** `{"optionId": "a"}`
**Response:** `{"responded": true}`, or `{"error": "..."}` (422 unknown
option, 403 poll closed or not this subject's audience/organisation).

## Database Changes

New schemas `portalPoll` and `portalPollResponse` in
`lib/Settings/portaliq_register.json`. No migration (OpenRegister).

## Nextcloud Integration

- Controllers: `PollController` (new)
- Services: `PollService` (new)
- Routes: `appinfo/routes.php` (+3)

## Security Considerations

`GET /portal/api/polls` and `POST /portal/api/polls/{id}/respond` both
resolve the subject from the bearer via `PortalSessionService::
resolveFromBearer()`, exactly as `ProposalController` already does —
`audience`, `organisation` and `subjectRef` are never client-supplied.

**Reading polls (D-1: the organisation check happens per row, not as a
query filter).** `PortalObjectReader::readCollection()`'s own contract
(read this session, `verifyScope()`) deliberately does NOT push
`organisation` as an OpenRegister query filter — it is enforced per row
after the read, alongside `scopeField` (here left `''`, since a poll is
not owned by one subject) via `organisationMatches()`. `PollService::
forSubject()` therefore calls `readCollection()` with `organisation:
$subject['organisation']` and `filter: ['audience' => $subject['audience']]`
— the audience filter narrows the OR query, the organisation check is
`verifyScope()`'s per-row tenant boundary, the same one every other
read in this app already relies on.

**Reading and writing a response (D-2: scoped exactly like
`ProposalQueueReader::mine()`).** A response is looked up by `scopeField:
'subjectRef', subjectRef: <bearer's own>`, filtered further by `pollId` —
the same "scope field + extra filter" shape `mine()`/`forSubject()` in
`ProposalQueueReader` already established. `respond()` re-reads the
existing response (if any) THIS way before deciding create vs. update, so
a client can never target another subject's response row by guessing its
id.

**A closed poll refuses a NEW OR CHANGED response, never a read.** Closed
means past `closesAt`; `respond()` checks this before touching the
response row, `forSubject()` does not filter closed polls out (a subject
who already answered should still see their own answer after the poll
closes).

## NL Design System

Not applicable — no frontend surface in this change (see proposal.md Out
of Scope).

## Trade-offs

- **No per-group targeting (see proposal.md Motivation).** Audience +
  organisation only; a follow-up once a `via`-style join exists for
  portaliq's own schemas, not only contributed collections.
- **`respond()` is UPDATE, not append-only.** Unlike `changeProposal`
  (which snapshots every change for review), a poll response has no
  reviewer and no drift concern — the subject's CURRENT answer is the only
  answer that matters, so one row per (poll, subject) is the right shape,
  not a log.
- **`organisation` on `create()` is an explicit staff-supplied field, not
  auto-resolved from the NC user's own org.** Auto-resolving would need
  investigating this app's staff-side org-membership resolution, which no
  existing `NoAdminRequired` route in this codebase does today (`Proposal
  Controller::proposeAsColleague()` never touches organisation at all).
  An explicit field keeps this change's surface area to what it actually
  needs; wiring an org-membership default is a natural, separate
  improvement once a second admin-facing poll-management surface exists
  to make the choice visible.

## Open Questions

None.

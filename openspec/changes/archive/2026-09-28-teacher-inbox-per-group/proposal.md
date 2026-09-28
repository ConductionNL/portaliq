---
kind: code
depends_on: [guardian-direct-messages]
---

# Proposal: teacher-inbox-per-group

## Summary

Adds the teacher-side inbox view over `guardian-direct-messages`'
primitives: every thread a staff member participates in, grouped by group
(direct threads under their own bucket), each with an unread count. Closes
finding 9.15 (`change-plan.md` portaliq row `teacher-inbox-per-group`).
STACKED on `guardian-direct-messages` (same lane) — this change adds no new
schema, only a read aggregation over that change's `messageThread`/`message`
data.

## Motivation

Aula: "messages route to a shared mailbox in the daycare facility/group,
giving staff a per-group inbox view". EduPage: teachers can "send messages
to teachers, classes or parents," implying an inbox scoped by audience/group.
IServ: Elternbriefe "to groups of students and/or individual students,"
implying per-group audience targeting from a teacher-side compose view.
Gibbon's own Messenger ships "Manage Messages" filterable to "my"/"all".
learniq: zero hits for a teacher inbox (findings 9.15).

## Affected Projects

- [x] Project: `portaliq` — a `TeacherInboxService` aggregation and one new
  `MessageStaffController` endpoint; no new schema.

## Scope

### In Scope

- `GET /api/staff/messages/inbox`: every thread the calling staff member
  participates in (via `GuardianMessagingLeafInterface::listThreads()`),
  bucketed by `groupRef` (group threads) or a `direct` bucket, each
  annotated with an unread count (messages whose `readBy` does not include
  the staff member's own subjectRef).
- Bucketing is computed from data the staff member is ALREADY authorized to
  see (their own `listThreads()` result) — no new authorization surface,
  reusing `guardian-direct-messages`' existing participation guarantees
  unchanged.

### Out of Scope

- Cross-staff visibility (a teacher seeing another teacher's threads) — not
  requested by 9.15 and would need a materially different authorization
  model.
- A dedicated UI — API-only, same as the base change.

## Approach

`TeacherInboxService` is a pure aggregation over
`GuardianMessagingLeafInterface::listThreads()`/`listMessages()` — it
introduces NO new authorization logic (participation is already decided by
the base change's `MessageThreadAccessGuard`) and NO new schema. This keeps
the stacked change small and honest about what it actually adds: a shaped
VIEW, not a new capability.

## New Dependencies

None.

## Impact

- `lib/Service/TeacherInboxService.php` (new).
- `lib/Controller/MessageStaffController.php` (one new method, `inbox()`).
- `appinfo/routes.php` (one new route).

## Cross-Project Dependencies

STACKED on `guardian-direct-messages` (same lane): requires
`GuardianMessagingLeafInterface`, `InAppMessagingLeaf`, and the
`messageThread`/`message` schemas that change ships. Cut from that branch,
not from `origin/development`.

## Risks

### Risk 1: An unread count drifts from the actual per-message read state
**Severity:** Low — **Mitigation:** the unread count is computed on every
request directly from each message's `readBy` array (no cached/denormalised
counter to go stale) — the same source of truth `markThreadRead()` writes to.

## Rollback Strategy

Removing the one new route and service reverts this change completely; the
base change's data and behaviour are untouched.

## Open Questions

None.

# guardian-direct-messaging Specification

**Status**: in-progress
**Scope**: portaliq
**OpenSpec changes**:

- [guardian-direct-messages](../../changes/guardian-direct-messages/)

## Purpose

A guardian-reachable, two-way messaging channel — teacher-to-parent (direct
threads) and teacher-to-group (group threads any in-audience guardian may
reply to) — closing finding 9.3. Designed against a
`GuardianMessagingLeafInterface` naming the shape OpenRegister's planned
`guardian-participant-messaging-leaf` would implement; `InAppMessagingLeaf`
is the first, self-contained implementation, so this app is not blocked on
that platform primitive shipping.

## ADDED Requirements

### Requirement: A guardian may start a direct thread with a teacher who teaches their child's group

A guardian MUST be able to create a `direct` `messageThread` with a staff
member, ONLY when that staff member actually teaches a group in the
guardian's own resolved audience (server-verified via
`GroupStaffFixtureReader` cross-checked against
`GuardianAudienceFixtureReader` — never a client-trusted participant list).
An attempt naming a staff member outside that reach MUST be refused before
any thread is created.

#### Scenario: A guardian starts a thread with their child's own teacher

- GIVEN a guardian whose audience includes `groep-5a`, taught by
  `staff-leerkracht-5a`
- WHEN the guardian creates a direct thread with `staff-leerkracht-5a`
- THEN the thread is created with both as participants
- @e2e exclude backend authorization contract — covered by PHPUnit; no
  distinct portaliq UI ships a teacher picker in this change

#### Scenario: A thread request naming an unreachable teacher is refused

- GIVEN a guardian whose audience does not include any group
  `staff-leerkracht-4c` teaches
- WHEN the guardian attempts to create a direct thread with
  `staff-leerkracht-4c`
- THEN the request is refused and no thread is created
- @e2e exclude fail-closed authorization invariant — pinned by
  `InAppMessagingLeafTest::testCreateThreadRefusesAStaffMemberOutsideTheGuardiansReach`;
  no UI surface

### Requirement: A group thread reaches every in-audience guardian, replies are two-way

A staff member MUST be able to create a `group` `messageThread` scoped to a
group they teach (verified via `GroupStaffFixtureReader`). Any guardian
whose own audience includes that group MUST be able to read and reply to
it; a guardian outside that group MUST NOT be able to read or post into it.

#### Scenario: A guardian in the group can read and reply

- GIVEN a group thread scoped to `groep-5a`
- WHEN a guardian whose audience includes `groep-5a` reads it
- THEN the thread's messages are visible, and posting a reply succeeds
- @e2e exclude backend participation contract — covered by PHPUnit; no
  distinct UI

#### Scenario: A guardian outside the group cannot read or post

- GIVEN the same group thread scoped to `groep-5a`
- WHEN a guardian whose audience does not include `groep-5a` attempts to
  read or post
- THEN both attempts are refused, identically to a non-existent thread (no
  existence oracle)
- @e2e exclude fail-closed participation invariant — pinned by
  `InAppMessagingLeafTest::testOutOfGroupGuardianCannotReadOrPost`; no UI

### Requirement: Every read/post/mark-read re-verifies participation server-side

`InAppMessagingLeaf::listMessages()`, `postMessage()`, and
`markThreadRead()` MUST re-verify, on EVERY call, that the calling subject
is a participant of the named thread (direct: in `participantRefs`; group:
their own group/staff-group audience includes the thread's `groupRef`) —
the SAME check `createThread()` uses, never a second implementation of it.
A non-participant's call MUST be refused identically whether the thread
exists or not (no existence oracle).

#### Scenario: A non-participant cannot post into a direct thread

- GIVEN a direct thread between guardian A and staff member S
- WHEN guardian B (not a participant) attempts to post into it
- THEN the post is refused and nothing is written
- @e2e exclude write-authorization invariant — pinned by
  `InAppMessagingLeafTest::testNonParticipantCannotPostIntoADirectThread`,
  asserting the write is never attempted; no UI surface

### Requirement: A message tracks who has read it

A `message` object MUST carry a `readBy[]` (subjectRefs). Marking a thread
read MUST record the calling subject exactly once per message, however many
times they read it (idempotent, mirroring the sibling
`news-and-newsletter-authoring` change's read-receipt discipline).

#### Scenario: Marking a thread read twice does not duplicate

- GIVEN a guardian who has already marked a thread's messages read
- WHEN they mark it read again
- THEN each message's `readBy` still contains exactly one entry for that
  guardian
- @e2e exclude idempotency invariant — pinned by PHPUnit; no UI surface

## Non-Functional Requirements

- **Performance:** participation checks read already-fetched thread/fixture
  rows; no unbounded query growth per message.
- **Accessibility:** no portaliq UI ships in this change (API-only); a
  future thread view MUST follow the app's existing NcSelect/NcTextField
  conventions.
- **Internationalization:** no new user-facing strings in this change
  (API-only); message bodies are free text, not translated here.
- **Security (ADR-005):** every participation check fails closed; a
  non-participant's read/post/create attempt is refused before any data is
  returned or written, with no existence oracle.

## Acceptance Criteria

- [ ] A guardian may create a direct thread only with a staff member who
  teaches a group in their own audience
- [ ] A group thread is readable/repliable by every in-audience guardian and
  by nobody else
- [ ] Every read/post/mark-read re-verifies participation, identically to
  the create path
- [ ] Marking a thread read twice never duplicates a `readBy` entry

## Notes

- Canonical evidence: findings 9.3 (`findings.md`), `tier-b-and-sibling.md`
  ("9.3's entire gap is that CohortTalk syncs learner+teacher membership
  only").
- The messaging-leaf interface names the seam for OpenRegister's planned
  `guardian-participant-messaging-leaf` (another lane, in flight); see
  `design.md` "Messaging leaf interface" for the exact swap point.
- `teacher-inbox-per-group` (a stacked follow-up change, same lane) builds
  the per-group staff inbox VIEW on top of the primitives this change ships;
  it does not modify this spec.

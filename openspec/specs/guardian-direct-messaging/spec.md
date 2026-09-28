# guardian-direct-messaging Specification

## Purpose
A guardian-reachable, two-way messaging channel — teacher-to-parent (direct
threads) and teacher-to-group (group threads any in-audience guardian may
reply to) — closing finding 9.3. Designed against a
`GuardianMessagingLeafInterface` naming the shape OpenRegister's planned
`guardian-participant-messaging-leaf` would implement; `InAppMessagingLeaf`
is the first, self-contained implementation, so this app is not blocked on
that platform primitive shipping.

## Requirements

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

### Requirement: A staff member's inbox is bucketed by group with an unread count

`GET /api/staff/messages/inbox` MUST return every thread the calling staff
member participates in (the SAME set `listThreads()` already returns —
introducing no new authorization surface), bucketed by the thread's
`groupRef` for group threads, or under a `direct` bucket for direct threads.
Each thread entry MUST carry an unread count: the number of that thread's
messages whose `readBy` does not include the calling staff member's own
subjectRef, computed fresh on every request from the same `message.readBy`
data `markThreadRead()` writes — never a cached counter.

#### Scenario: Threads are bucketed correctly

- GIVEN a staff member participating in two group threads (`groep-5a`,
  `groep-3b`) and one direct thread
- WHEN they request their inbox
- THEN the response has a `groep-5a` bucket with one thread, a `groep-3b`
  bucket with one thread, and a `direct` bucket with one thread
- @e2e exclude backend aggregation contract — covered by PHPUnit; no
  distinct portaliq UI ships an inbox view in this change

#### Scenario: An unread count reflects the current read state exactly

- GIVEN a thread with three messages, two of which the staff member has not
  yet marked read
- WHEN they request their inbox before and after calling `markRead`
- THEN the unread count is 2 before and 0 after, with no intermediate
  cached value
- @e2e exclude backend read-state contract — pinned by
  `TeacherInboxServiceTest::testUnreadCountReflectsReadByExactly`; no UI
  surface

#### Scenario: The inbox never exposes a thread the staff member does not participate in

- GIVEN a group thread for `groep-4c`, which the calling staff member does
  not teach
- WHEN they request their inbox
- THEN that thread does not appear — the inbox is exactly `listThreads()`'s
  own result, reshaped, never a broader query
- @e2e exclude no-new-authorization-surface invariant — pinned by
  `TeacherInboxServiceTest::testInboxNeverExceedsListThreadsOwnResult`; no
  UI surface

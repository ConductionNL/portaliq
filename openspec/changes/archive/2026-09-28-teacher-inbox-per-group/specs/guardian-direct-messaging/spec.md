# guardian-direct-messaging Specification (delta: teacher-inbox-per-group)

## ADDED Requirements

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

## Notes

- This requirement was added by the `teacher-inbox-per-group` change
  (delta: `openspec/changes/teacher-inbox-per-group/specs/
  guardian-direct-messaging/spec.md`), stacked on `guardian-direct-messages`
  in the same lane; same sync discipline until it archives.

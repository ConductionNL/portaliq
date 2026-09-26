---
kind: code
---

# Proposal: guardian-direct-messages

## Summary

Adds a two-way messaging channel, teacher-to-parent (direct) and
teacher-to-group (broadcast-and-reply), designed against a messaging-leaf
interface a future OpenRegister primitive would implement, shipped for now
as a minimal in-app store. Closes finding 9.3
(`change-plan.md` portaliq row `guardian-direct-messages`).

## Motivation

learniq's own `CohortTalk` (Nextcloud Talk integration) syncs learner and
teacher membership only — a guardian is never a Talk participant
(`tier-b-and-sibling.md`: "9.3's entire gap is that CohortTalk syncs
learner+teacher membership only"). Every credible competitor in the corpus
ships guardian-reachable messaging: Aula ("opportunities to send messages...
including parent-teacher and group messaging, via a shared mailbox"), Wilma
("guardians can... communicate with teachers"), Edupage ("sending messages
to teachers, classes or parents"), IServ (a "Messenger-Raum" per group plus
Elternbriefe), and Parnassys Parro (Privégesprek, Kindgesprek,
Groepsgesprek). No corpus source suggests a guardian gets FULL Talk-style
presence/typing features — a threaded, read-tracked message exchange is the
common shape, which is what this change builds.

## Affected Projects

- [x] Project: `portaliq` — new `messageThread`/`message` OpenRegister
  schemas, a `GuardianMessagingLeafInterface` + its first (in-app)
  implementation, guardian and staff controllers.

## Scope

### In Scope

- A `GuardianMessagingLeafInterface` naming the shape a future OpenRegister
  messaging leaf (`guardian-participant-messaging-leaf`, another lane, in
  flight) would implement: create a thread, post a message, list a
  subject's threads, list a thread's messages, mark read.
- `InAppMessagingLeaf`, the FIRST implementation of that interface, backed by
  portaliq's own `messageThread`/`message` schemas — no external Talk
  dependency.
- Direct threads (one guardian + one staff member) and group threads (one
  staff member's group broadcast, any guardian in that group may reply).
- Guardian read/post/reply, scoped to threads they participate in; an
  out-of-thread id 404s identically to a non-existent one.
- A minimal staff-side reply capability (list my threads, post a message) —
  the FULL per-group staff INBOX view is `teacher-inbox-per-group`, a
  separate, stacked change.
- A `groupStaffFixture` — the interim stand-in for "which staff teach which
  group", analogous to the sibling changes' `guardianAudienceFixture`, so a
  guardian can only start a thread with a teacher who actually teaches their
  child's group.

### Out of Scope

- The per-group staff INBOX UI/aggregation — `teacher-inbox-per-group`
  (stacked on this change).
- Automatic translation of messages (finding 9.4, `hermiq`'s
  `message-translation-delegate`).
- Push notification on a new message — `push-notifications-quiet-hours`.
- Presence, typing indicators, read-by-multiple-parties display, file
  attachments — none of the corpus evidence names these as expected; a
  threaded exchange with a read flag is the documented common shape.

## Approach

The interface/implementation split is the point: `GuardianMessagingLeafInterface`
is written against what `guardian-participant-messaging-leaf` (OpenRegister,
another lane) would need to expose — create/post/list/read — so that when
that leaf ships, `InAppMessagingLeaf` can be swapped for an OR-leaf-backed
implementation behind the SAME interface with no controller change. Until
then, `InAppMessagingLeaf` is the only implementation, using this app's own
OpenRegister schemas.

## New Dependencies

None.

## Impact

- `lib/Settings/portaliq_register.json` — new schemas `messageThread`,
  `message`, `groupStaffFixture`.
- `lib/Service/Messaging/GuardianMessagingLeafInterface.php`,
  `InAppMessagingLeaf.php`, `GroupStaffFixtureReader.php`.
- `lib/Controller/MessageGuardianController.php`,
  `lib/Controller/MessageStaffController.php`.
- `appinfo/routes.php` — new routes under `/api/messages`.

## Cross-Project Dependencies

Depends on OpenRegister's `guardian-participant-messaging-leaf` (another
lane, in flight) for a real cross-app messaging primitive; this change ships
a working, self-contained implementation now and names the exact seam
(`GuardianMessagingLeafInterface`) to swap later.

## Risks

### Risk 1: A guardian starts a thread with a teacher outside their child's group
**Severity:** High — **Mitigation:** `InAppMessagingLeaf::createThread()`
re-verifies, server-side, that the named staff member actually teaches a
group in the guardian's own resolved audience (`GroupStaffFixtureReader`
cross-checked against `GuardianAudienceFixtureReader`) before creating the
thread — never trusts a client-supplied participant list at face value.

### Risk 2: A participant reads or posts into a thread they do not belong to
**Severity:** High — **Mitigation:** every read/post/mark-read call
re-verifies thread participation server-side (direct: subjectRef in
`participantRefs`; group: the subject's own group/staff-group audience
includes the thread's `groupRef`) — the SAME check the create path uses, not
a second implementation of it.

## Rollback Strategy

Additive schemas; disabling the feature is removing the new routes. No
migration needed to roll back.

## Open Questions

None.

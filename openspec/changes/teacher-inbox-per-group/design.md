# Design: teacher-inbox-per-group

## Architecture Overview

```
staff --> MessageStaffController::inbox() --> TeacherInboxService
                                                 --> GuardianMessagingLeafInterface::listThreads(staffRef, isStaff: true)
                                                 --> GuardianMessagingLeafInterface::listMessages(threadId, staffRef, isStaff: true)  (per thread, for the unread count)
```

No new schema, no new authorization logic — `TeacherInboxService` is a pure
reshape of data the interface already scopes correctly.

## API Design

### `GET /api/staff/messages/inbox`
Response:
```json
{
  "groep-5a": [{"id": "thread-1", "unreadCount": 2, ...}],
  "direct": [{"id": "thread-2", "unreadCount": 0, ...}]
}
```

## Nextcloud Integration

- Controllers: `MessageStaffController::inbox()` (new method on the
  existing controller from `guardian-direct-messages`, `#[NoAdminRequired]`,
  unchanged posture).
- Services: `TeacherInboxService` (new).

## Security Considerations

No new authorization surface: `TeacherInboxService` calls
`GuardianMessagingLeafInterface::listThreads()`/`listMessages()` with the
CALLING staff member's own subjectRef — it can never see another staff
member's threads, because the interface itself would refuse them
(`MessageThreadAccessGuard::isParticipant()`, unchanged by this change).

## NL Design System

No UI ships in this change (API-only, per proposal.md Scope).

## File Structure

```
lib/
  Service/
    TeacherInboxService.php
  Controller/
    MessageStaffController.php   (one new method: inbox())
appinfo/routes.php
tests/Unit/Service/TeacherInboxServiceTest.php
```

## Seed Data

None — this change adds no schema; it reads the base change's existing
`messageThread`/`message` objects (which themselves have no seed rows,
being write-only through the API).

## Risks / Trade-offs

- [Risk] Computing the unread count via a full `listMessages()` call per
  thread is O(threads × messages) per inbox request → [Mitigation]
  acceptable at this app's scale (a school's staff and thread volume is
  small); documented here rather than silently assumed fine, so a future
  performance pass has a named starting point if it ever matters.

## Migration Plan

No schema change.

## Open Questions

None.

# Design: guardian-direct-messages

## Architecture Overview

```
guardian --> MessageGuardianController --> InAppMessagingLeaf (implements GuardianMessagingLeafInterface)
staff    --> MessageStaffController    --> InAppMessagingLeaf
                                             --> ObjectService (OR: messageThread, message)
                                             --> GroupStaffFixtureReader (interim: which staff teach which group)
                                             --> GuardianAudienceFixtureReader (interim: which groups a guardian reaches)
```

## Messaging leaf interface

`GuardianMessagingLeafInterface` names exactly the operations a future
OpenRegister `guardian-participant-messaging-leaf` primitive would need to
expose, so this app's controllers never talk to OpenRegister objects
directly for messaging:

```php
interface GuardianMessagingLeafInterface {
    public function createThread(string $kind, array $participantRefs, ?string $groupRef, string $createdBy): ?string;
    public function postMessage(string $threadId, string $senderRef, bool $senderIsStaff, string $body): bool;
    public function listThreads(string $subjectRef, bool $isStaff): array;
    public function listMessages(string $threadId, string $subjectRef, bool $isStaff): ?array;
    public function markThreadRead(string $threadId, string $subjectRef, bool $isStaff): bool;
}
```

`InAppMessagingLeaf` is the only implementation in this change. When
`guardian-participant-messaging-leaf` ships, a second implementation can be
added and swapped in DI (`lib/AppInfo/Application.php`) with NO controller
change — the controllers depend on the interface, never the concrete class.

## Participation model

- **`kind: 'direct'`**: `participantRefs` is the explicit list (one guardian
  subjectRef, one staff subjectRef). Participation = subjectRef in that
  list.
- **`kind: 'group'`**: `groupRef` names the group. Participation for a
  GUARDIAN = the group is in their own `GuardianAudienceFixtureReader`
  audience; for STAFF = the group is in their own
  `GroupStaffFixtureReader::groupsTaughtBy()`.

Both `createThread()` and every subsequent
`listMessages()`/`postMessage()`/`markThreadRead()` call the SAME
`isParticipant()` private predicate — one implementation, not two that could
drift (mirrors the sibling changes' "one matcher, every call site" pattern).

## Declarative-vs-imperative decision (ADR-031)

- **Read tracking** (`readBy[]`): imperative (`InAppMessagingLeaf::markThreadRead()`),
  same idempotent-append shape as the sibling `NewsReadReceiptService` —
  ADR-031's exception applies identically.
- No lifecycle/aggregation/notification behaviour is introduced by this
  change beyond that.

## API Design

### `POST /api/messages/threads` (guardian)
Body: `{staffRef}` (direct) — creates a direct thread if the staff member
teaches a group in the guardian's audience. 403 otherwise.

### `POST /api/messages/threads/group` (staff)
Body: `{groupRef}` — creates a group thread if the caller teaches that
group. 403 otherwise.

### `GET /api/messages/threads` (guardian and staff, same endpoint shape on each controller)
Every thread the caller participates in.

### `GET /api/messages/threads/{id}/messages`
Every message in a thread the caller participates in; 404 otherwise (no
existence oracle).

### `POST /api/messages/threads/{id}/messages`
Body: `{body}`. 404 if not a participant.

### `POST /api/messages/threads/{id}/read`
Marks every message in the thread read for the caller. 404 if not a
participant.

## Nextcloud Integration

- Controllers: `MessageGuardianController` (`PortalProtected`,
  `#[PublicPage]`), `MessageStaffController` (`#[NoAdminRequired]`).
- Services: `GuardianMessagingLeafInterface`, `InAppMessagingLeaf`,
  `GroupStaffFixtureReader`.
- DI: `Application::register()` binds the interface to `InAppMessagingLeaf`.

## Security Considerations

- `createThread` re-verifies the OTHER participant's reach server-side
  (proposal.md Risk 1) — a client cannot smuggle an arbitrary staff/guardian
  into a thread.
- Every subsequent call re-verifies the CALLER's own participation
  (proposal.md Risk 2) — no second, divergent implementation of the check.
- A non-participant's read/post/create attempt fails identically to a
  non-existent thread — no existence oracle.

## NL Design System

No UI ships in this change (API-only, per proposal.md Scope).

## File Structure

```
lib/
  Service/
    Messaging/
      GuardianMessagingLeafInterface.php
      InAppMessagingLeaf.php
      MessageThreadAccessGuard.php   (authorization decisions, split out for complexity)
      MessageStore.php               (OR persistence plumbing, split out for complexity)
    GroupStaffFixtureReader.php
    GuardianAudienceFixtureReader.php   (own copy, shared shape with sibling changes)
    NewsAudienceMatcher.php             (reused, unmodified)
  Controller/
    MessageGuardianController.php
    MessageStaffController.php
lib/Settings/portaliq_register.json    (schemas: messageThread, message, groupStaffFixture, guardianAudienceFixture)
appinfo/routes.php
tests/Unit/Service/Messaging/InAppMessagingLeafTest.php
tests/Unit/Service/Messaging/MessageThreadAccessGuardTest.php
tests/Unit/Service/GroupStaffFixtureReaderTest.php
tests/Unit/Controller/MessageGuardianControllerTest.php
tests/Unit/Controller/MessageStaffControllerTest.php
```

## Seed Data

### Schema: `groupStaffFixture`

| Field | Row 1 | Row 2 | Row 3 |
| --- | --- | --- | --- |
| groupRef | `groep-5a` | `groep-3b` | `groep-4c` |
| staffRefs | `["staff-leerkracht-5a"]` | `["staff-leerkracht-3b"]` | `["staff-leerkracht-4c"]` |

### Schema: `guardianAudienceFixture`

Same 4 rows as the sibling changes (`fixture-guardian-anna-devries`,
`fixture-guardian-piet-bakker`, `fixture-guardian-fatima-elamrani`,
`fixture-guardian-noor-yilmaz`) — values not repeated here, see
`news-and-newsletter-authoring`'s design.md.

### Schema: `messageThread` / `message`

No seed rows — both are created only through the API (a thread with no
messages is not useful seed content); an empty starting state matches every
other transactional schema in this app.

## Risks / Trade-offs

- [Risk] `GroupStaffFixtureReader` is a second interim fixture (alongside
  `guardianAudienceFixture`) → [Mitigation] both are named and scoped
  identically to the sibling changes' seam, retired together once
  learniq's real contribution ships both audience and staff-assignment data.

## Migration Plan

Additive schemas only.

## Open Questions

None.

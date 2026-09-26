# Design: events-and-signups

## Architecture Overview

Mirrors `news-and-newsletter-authoring` exactly: `event`/`eventRsvp`/
`eventSignup` are `portaliq` OpenRegister objects; the guardian read path is
a dedicated `EventFeedReader` (not the generic contribution-contract engine,
for the identical reason — 3-way school/group/child targeting is not a
single-`via`-join shape). RSVP and sign-up are guardian WRITES, each with
its own idempotency/capacity rule that the generic writer's plain merge
cannot express.

```
staff --> EventController --> ObjectService (OR)
guardian --> EventGuardianController::feed --> EventFeedReader --> GuardianAudienceFixtureReader
guardian RSVP --> EventGuardianController::rsvp --> EventRsvpService (upsert)
guardian signup --> EventGuardianController::signup --> EventSignupService (capacity-checked)
```

## Audience source seam

Identical fixture to `news-and-newsletter-authoring`
(`guardianAudienceFixture` schema, `GuardianAudienceFixtureReader`). Both
changes are cut independently from `origin/development` per this lane's
brief; see that change's design.md for the full rationale and the one-file
swap point when learniq's real collection ships. This change carries its own
copy rather than depending on an unmerged sibling PR — proposal.md Risk 1
names the resulting (trivial) merge interaction.

## Declarative-vs-imperative decision (ADR-031)

- **Lifecycle** (`event` draft → published): same imperative pattern as
  `newsItem`/`page`, for the same consistency reason.
- **Capacity check**: imperative (`EventSignupService`) — ADR-031's
  exception applies (a domain-rule selector re-counting sign-ups is not
  shaped for `x-openregister-aggregations`).
- **RSVP upsert**: imperative (`EventRsvpService`) — the "find existing by
  guardian+child+event, then update or create" selector is the same shape
  of exception as `NewsReadReceiptService`'s idempotent append in the sibling
  change.

## API Design

### `POST /api/events`
Staff creates a draft `event`.

### `PUT /api/events/{id}/publish`
Staff publishes.

### `GET /api/events/feed`
Guardian reads published, in-audience events, each annotated with the
guardian's own RSVP/signup status (never another guardian's).

### `POST /api/events/{id}/rsvp`
Guardian upserts an RSVP. Body: `childRef`, `response` (`yes`|`no`|`maybe`).
404 if the event is not in the guardian's audience or `rsvpEnabled` is false.

### `POST /api/events/{id}/signup`
Guardian signs up for a role. Body: `roleId`, `childRef?`, `note?`. 422
`{"error": "role-full"}` if the role is at capacity; 404 if the event/role is
not in the guardian's reach.

## Nextcloud Integration

- Controllers: `EventController` (staff, `#[NoAdminRequired]`),
  `EventGuardianController` (guardian, `PortalProtected` + `#[PublicPage]`).
- Services: `EventFeedReader`, `EventRsvpService`, `EventSignupService`,
  `GuardianAudienceFixtureReader` (shared shape with the sibling change).
- No new Mappers — OpenRegister `ObjectService` via `ContainerInterface`.

## Security Considerations

- RSVP/signup re-verify the event is in the CALLING guardian's own audience
  before accepting a write — an out-of-audience event id 404s identically to
  a non-existent one.
- Capacity enforcement runs server-side before any write (no UI-only gate).
- No new permission model for staff authoring — same posture as
  `CmsEditorController`.

## NL Design System

Staff authoring form reuses `NcTextField`/`NcSelect`/`NcButton`, `inputLabel`
on every select (hydra-gate-nc-input-labels).

## File Structure

```
lib/
  Controller/
    EventController.php
    EventGuardianController.php
  Service/
    EventFeedReader.php
    EventRsvpService.php
    EventSignupService.php
    GuardianAudienceFixtureReader.php
    NewsAudienceMatcher.php   (reused verbatim from the sibling change's shape)
lib/Settings/portaliq_register.json   (schemas: event, eventRsvp, eventSignup, guardianAudienceFixture)
appinfo/routes.php
tests/Unit/Service/EventFeedReaderTest.php
tests/Unit/Service/EventRsvpServiceTest.php
tests/Unit/Service/EventSignupServiceTest.php
tests/Unit/Controller/EventControllerTest.php
tests/Unit/Controller/EventGuardianControllerTest.php
```

## Seed Data

### Schema: `guardianAudienceFixture`

Same 4 rows as `news-and-newsletter-authoring` (`fixture-guardian-anna-devries`,
`fixture-guardian-piet-bakker`, `fixture-guardian-fatima-elamrani`,
`fixture-guardian-noor-yilmaz`) — see that change's design.md for the exact
values; not repeated here to avoid the two documents drifting on values that
must stay identical while both are open.

### Schema: `event`

| Field | Object 1 | Object 2 |
| --- | --- | --- |
| title | "Schoolreisje groep 5a" | "Sportdag: hulp gevraagd" |
| target | `{"groupRefs":["groep-5a"]}` | `{"schoolRef":"school-de-regenboog"}` |
| status | published | published |
| rsvpEnabled | true | false |
| signupRoles | `[]` | `[{"id":"begeleiding","label":"Begeleiding","capacity":4},{"id":"materiaal","label":"Limonade meenemen","capacity":2}]` |

### Schema: `eventRsvp` / `eventSignup`

No seed rows — both are guardian-write-only records; an empty starting state
matches every other transactional schema in this app (e.g. `portalSubmission`).

## Risks / Trade-offs

- [Risk] Capacity check race under concurrent sign-ups → [Mitigation]
  documented as a known limitation (proposal.md Risk 2); a double-booked
  slot is a data-quality issue for staff to resolve, not a security
  boundary.

## Migration Plan

Additive schemas only.

## Open Questions

None.

# Tasks: events-and-signups

## Implementation Tasks

### Task 1: Register schemas and seed data
- **spec_ref**: `openspec/changes/events-and-signups/design.md#seed-data`
- **files**: `lib/Settings/portaliq_register.json`
- **acceptance_criteria**:
  - GIVEN the register file WHEN validated as JSON THEN `event`, `eventRsvp`, `eventSignup`, `guardianAudienceFixture` schemas and seed events exist
- [x] Implement
- [x] Test (JSON validity + `PortaliqRegisterConfigTest`/`RegisterAuthorizationTest` kept green)

### Task 2: GuardianAudienceFixtureReader (shared seam)
- **spec_ref**: `openspec/changes/events-and-signups/design.md#audience-source-seam`
- **files**: `lib/Service/GuardianAudienceFixtureReader.php`, `lib/Service/NewsAudienceMatcher.php`
- **acceptance_criteria**:
  - GIVEN a subjectRef with a fixture row WHEN resolved THEN school/group/child refs returned; absent row THEN empty audience
- [x] Implement
- [x] Test (reused test suite from the sibling change's shape)

### Task 3: EventFeedReader
- **spec_ref**: `openspec/changes/events-and-signups/specs/portaliq-cms/spec.md#requirement-an-event-is-authored-per-school-group-or-child-with-guardian-rsvp`
- **files**: `lib/Service/EventFeedReader.php`
- **acceptance_criteria**:
  - GIVEN a guardian's resolved audience WHEN the feed is read THEN only published in-audience events return, annotated with the guardian's own RSVP
- [x] Implement
- [x] Test

### Task 4: EventController (staff authoring)
- **spec_ref**: `openspec/changes/events-and-signups/design.md#api-design`
- **files**: `lib/Controller/EventController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN staff creates and publishes an event WHEN a matching guardian reads the feed THEN it appears
- [x] Implement
- [x] Test

### Task 5: EventRsvpService (upsert)
- **spec_ref**: `openspec/changes/events-and-signups/specs/portaliq-cms/spec.md#requirement-an-event-is-authored-per-school-group-or-child-with-guardian-rsvp`
- **files**: `lib/Service/EventRsvpService.php`
- **acceptance_criteria**:
  - GIVEN a guardian RSVPs twice for the same child+event WHEN inspected THEN exactly one RSVP record exists, holding the latest response
- [x] Implement
- [x] Test

### Task 6: EventSignupService (capacity-checked)
- **spec_ref**: `openspec/changes/events-and-signups/specs/portaliq-cms/spec.md#requirement-a-sign-up-role-enforces-its-capacity-server-side`
- **files**: `lib/Service/EventSignupService.php`
- **acceptance_criteria**:
  - GIVEN a role at capacity WHEN a sign-up is attempted THEN it is refused and nothing is written
  - GIVEN a role with room WHEN a sign-up is attempted THEN it is recorded
- [x] Implement
- [x] Test

### Task 7: EventGuardianController (feed, rsvp, signup)
- **spec_ref**: `openspec/changes/events-and-signups/design.md#api-design`
- **files**: `lib/Controller/EventGuardianController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN no resolved subject WHEN any guardian endpoint is called THEN 401
  - GIVEN a valid RSVP/signup request WHEN handled THEN the matching service's result decides the response code
- [x] Implement
- [x] Test

## Quality checklist

- All new business logic covered by PHPUnit unit tests
- Dutch (`nl`) and English (`en`) labels for staff authoring UI (ADR-007) — deferred with the UI itself (see Blocked below)
- `openspec validate events-and-signups` passes
- SPDX headers on every new PHP file

## Blocked / deferred

- Staff Vue authoring UI: not built in this change, same rationale as
  `news-and-newsletter-authoring` Task 8 — backend is complete and reachable
  via the raw API; follow-up PR.

## Verification
- [x] All tasks checked off (staff UI explicitly out of scope, not a task here)
- [x] `openspec validate events-and-signups --strict` passes
- [x] Diff-scoped gates green on touched files (php -l, phpcs, phpunit --filter)

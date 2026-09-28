# portaliq-cms Specification (delta: events-and-signups)

## ADDED Requirements

### Requirement: An event is authored per school, group or child, with guardian RSVP

An `event` OpenRegister schema (register `portaliq`) MUST carry `title`,
`description`, `start`/`end` (date-time), the same `target` shape as
`newsItem` (`schoolRef?`/`groupRefs[]?`/`childRefs[]?`, at least one
present), `status` (`draft`|`published`), `rsvpEnabled` (bool), and an
optional `signupRoles[]` (`{id, label, capacity}`). A guardian MUST be able
to RSVP (`yes`|`no`|`maybe`) for one of their own children to a published,
in-audience event with `rsvpEnabled: true`; a second RSVP by the same
guardian for the same child on the same event MUST update the existing
response rather than create a second one (upsert, not append).

#### Scenario: A group-targeted event reaches only that group's guardians

- GIVEN a published `event` targeting one group with `rsvpEnabled: true`
- WHEN a guardian whose audience includes that group reads the feed
- THEN the event appears
- AND a guardian outside that group, school and every targeted child never
  sees it
- @e2e exclude backend audience-scoping contract — identical matcher to
  `news-and-newsletter-authoring`'s `NewsAudienceMatcher`; covered by
  PHPUnit, no distinct portaliq UI ships a group picker in this change

#### Scenario: A second RSVP updates, it does not duplicate

- GIVEN a guardian who already RSVP'd `maybe` for their child on an event
- WHEN they RSVP `yes` for the same child on the same event
- THEN exactly one RSVP record exists for that guardian+child+event, now
  reading `yes`
- @e2e exclude idempotency invariant — pinned by
  `EventRsvpServiceTest::testASecondRsvpUpdatesRatherThanDuplicates`; no UI
  surface distinguishes a first RSVP from a change of mind

### Requirement: A sign-up role enforces its capacity server-side

A `signupRoles` entry (`{id, label, capacity}`) accepts guardian sign-ups
(activiteitenplanner/ouderhulp — findings 9.8) up to its `capacity`. A
sign-up attempt against a role already at capacity MUST be refused with a
machine-readable reason and MUST NOT be recorded — the check happens
server-side before any write, not only as a disabled button in the UI (the
gap several corpus competitors leave implicit, e.g. Kwieb's "participation
limits").

#### Scenario: A sign-up is accepted while capacity remains

- GIVEN a role with `capacity: 2` and zero current sign-ups
- WHEN a guardian signs up
- THEN the sign-up is recorded and the role shows 1 of 2 taken
- @e2e exclude backend capacity contract — covered by PHPUnit; no distinct
  UI ships a live capacity counter in this change

#### Scenario: A sign-up is refused once the role is full

- GIVEN a role with `capacity: 1` and one existing sign-up
- WHEN a second guardian attempts to sign up for the same role
- THEN the attempt is refused before any write, with a reason naming the
  role is full
- @e2e exclude fail-closed capacity invariant — pinned by
  `EventSignupServiceTest::testASignupIsRefusedOnceTheRoleIsFull`, asserting
  the write is never attempted; no UI surface

## Non-Functional Requirements

- **Performance:** the capacity check re-counts sign-ups for one role
  (bounded, per-event) immediately before accepting; no additional
  OpenRegister round trip beyond the existing audience-matching read.
- **Accessibility:** the staff authoring form follows the same NcSelect/
  NcTextField pattern as `portal-cms-admin-ui`'s existing page editor.
- **Internationalization:** authoring labels and guardian-facing strings
  ship Dutch and English (ADR-007).
- **Security (ADR-005):** an unresolvable audience is "not in audience"; a
  capacity race defaults to refusing the later write, never silently
  over-booking past the declared capacity.

## Acceptance Criteria

- [ ] A published, in-audience, RSVP-enabled event is readable by a matching
  guardian and absent for a non-matching one
- [ ] A second RSVP by the same guardian for the same child updates rather
  than duplicates
- [ ] A sign-up against a role with remaining capacity succeeds; one against
  a full role is refused before any write

## Notes

- These requirements were added by the `events-and-signups` change (delta:
  `openspec/changes/events-and-signups/specs/portaliq-cms/spec.md`); same
  sync discipline as the app's other change deltas until this change
  archives.
- Shares the `guardianAudienceFixture` interim seam with
  `news-and-newsletter-authoring` (same lane) — see that change's design.md
  "Audience source seam" for the swap point once learniq's
  `portal-contribution-guardian-audiences` ships.

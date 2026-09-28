---
kind: code
---

# Proposal: events-and-signups

## Summary

Adds group/school-targeted events with guardian RSVP, and sign-up roles on an
event for volunteers and materials (activiteitenplanner/ouderhulp). Closes
findings 9.6 and 9.8 (`change-plan.md` portaliq row `events-and-signups`).

## Motivation

Every corpus competitor with a parent app ships this: Social Schools
"Groepsagenda's met intekenlijsten" and Kwieb "publiceer activiteiten en
nodig ouders optioneel uit om deel te nemen" (findings 9.6); Parnassys Parro
"Vraag om aanwezigheid" on agenda items and a dedicated "Activiteitenplanner"
plus "Vrijwilligers uitvragen" (findings 9.6, 9.8); Klasbord's teachers
"intekenmomenten waar ouders zich op in kunnen schrijven" (findings 9.8).
learniq has no agenda/event surface for parents at all.

## Affected Projects

- [x] Project: `portaliq` — new `event`/`eventRsvp`/`eventSignup`
  OpenRegister schemas, staff authoring controller, guardian RSVP/signup
  endpoints with capacity enforcement.

## Scope

### In Scope

- `event` schema targeted at a school, group(s) or specific children (same
  targeting shape as `news-and-newsletter-authoring`'s `newsItem`, for
  consistency).
- Guardian RSVP (yes/no/maybe) per child, per event — one RSVP per
  guardian+child+event, upserted, not append-only.
- Sign-up roles on an event (`signupRoles`, each with a capacity) for
  volunteers and materials; a guardian's sign-up is rejected once a role's
  capacity is reached — checked server-side, not just hidden in the UI.
- Staff authoring: create/update/publish an event; list its RSVPs and
  sign-ups.
- Guardian read: published, in-audience events with the CALLING guardian's
  own RSVP/signup status attached.

### Out of Scope

- Gesprekkenplanner (finding 9.7, parent books a 1:1 teacher slot with
  buffers) — not in this round's M1 rows for this change; a materially
  different scheduling shape (staff availability, buffers, per-conversation
  slots) that would double this change's size.
- Calendar export/subscription (iCal) — not requested by 9.6/9.8; a natural
  follow-up once events exist as data.
- Push notification on event creation — covered by
  `push-notifications-quiet-hours`, a separate change.

## Approach

Mirrors `news-and-newsletter-authoring`'s architecture exactly: an
`EventFeedReader` (guardian read, same fail-closed/no-oracle discipline) over
the SAME interim `guardianAudienceFixture` seam
(`GuardianAudienceFixtureReader`) that change ships. Both changes are cut
from `origin/development` independently (per this lane's brief), so this
change carries its OWN copy of the fixture schema and reader — see Risks.

## New Dependencies

None.

## Impact

- `lib/Settings/portaliq_register.json` — new schemas `event`, `eventRsvp`,
  `eventSignup`, `guardianAudienceFixture` (+ seed data).
- `lib/Controller/EventController.php` (staff), `lib/Controller/
  EventGuardianController.php` (guardian) — new.
- `lib/Service/EventFeedReader.php`, `EventRsvpService.php`,
  `EventSignupService.php`, `GuardianAudienceFixtureReader.php` — new.
- `appinfo/routes.php` — new routes under `/api/events`.

## Cross-Project Dependencies

Depends on learniq's `portal-contribution-guardian-audiences` for the real
audience source, same as `news-and-newsletter-authoring` — see that
change's design.md for the swap point, which applies identically here.

## Risks

### Risk 1: Duplicate `guardianAudienceFixture` schema/reader across two sibling PRs
**Severity:** Low — **Mitigation:** `news-and-newsletter-authoring` (same
lane) ships the identical schema and `GuardianAudienceFixtureReader` class.
Whichever of the two PRs merges second will show a conflict on
`lib/Settings/portaliq_register.json` and a duplicate-file conflict on the
reader — both trivially resolved (the content is intended to be identical;
keep either side). Noted here rather than silently risking a double
declaration once both land.

### Risk 2: A capacity check that races under concurrent sign-ups
**Severity:** Medium — **Mitigation:** `EventSignupService` re-counts
current sign-ups for the role immediately before accepting a new one
(best-effort, not a DB-level lock — OpenRegister offers no row lock through
this app's read path); documented as a known limitation in design.md rather
than silently assumed safe. A double-booked slot is a staff-visible data
quality issue, not a security boundary.

## Rollback Strategy

Additive schemas only; disabling the feature is removing the new routes.

## Open Questions

None.

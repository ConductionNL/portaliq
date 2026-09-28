# Design: extracurricular-activity-offer

## Architecture Overview

Mirrors `events-and-signups`: OpenRegister objects in the `portaliq` register,
a guardian path behind the portal bearer, a staff path behind a Nextcloud
session. One new piece: `ActivityStore` wraps the OpenRegister calls for the
three schemas, so the rules live in services that test against a fake store.

```
staff    --> ActivityController         --> ActivitySignupService (places, promotion, roster)
                                         --> ActivityAttendanceService
guardian --> ActivityGuardianController --> ActivityFeedReader (audience, own children)
                                         --> ActivitySignupService (sign up, withdraw)
all services --> ActivityStore --> OpenRegister ObjectService (_rbac off, portaliq is the scoper)
audience --> GuardianAudienceFixtureReader + NewsAudienceMatcher (unchanged)
```

## API Design

Endpoints, bodies and error codes are in `contract.md`. Routes:

```
POST /api/activities                          activity#create
PUT  /api/activities/{id}/open                activity#open
PUT  /api/activities/{id}/close               activity#close
PUT  /api/activities/{id}/supervisors         activity#supervisors
GET  /api/activities/{id}/roster              activity#roster
PUT  /api/activities/{id}/attendance          activity#attendance
GET  /api/activities/feed                     activityGuardian#feed
POST /api/activities/{id}/signup              activityGuardian#signup
POST /api/activities/{id}/withdraw            activityGuardian#withdraw
```

## Database Changes

None: three OpenRegister schemas, no tables.

## Decisions

### D1: A new object, not more fields on `schoolEvent`

Recon E question 2, option B. An event is one start and one end; a club is a
term of sessions with a roster, a waiting list and supervision. Stretching
`schoolEvent` would give every one-off event fields it never uses.

### D2: Places come from capacity and supervision

`places = min(capacity, distinctSupervisors * childrenPerSupervisor)`, or
`capacity` when no ratio is set. The ratio is enforced where it matters: a
child is confirmed only into a place the supervision covers. Fewer supervisors
never removes a child who already has a place; it only stops new
confirmations. More supervisors promote from the waiting list at once.

### D3: The waiting list is an ordered status, not a second object

A waitlisted sign-up is an `activitySignup` with `status: waitlisted`; the
order is `signedUpAt`, then id. Promotion flips it to `confirmed` and stamps
`confirmedAt`. This is the pattern learniq's `AdmissionsWaitlistPromoter`
uses for admissions, which recon E names.

### D4: Payment is a reference per place

A shillinq `PaymentRequest` has one debtor, so the reference sits on the
sign-up (`paymentRequestRef`), and the activity only says a payment is
requested. Portaliq stores no amount (D19). Amended by
`activity-offer-contract-fix`: portaliq raises through shillinq and writes the
reference itself; the request's subject is the activity, the child the
beneficiary.

### D5: A guardian signs up only their own child

The child must be in the guardian's resolved `childRefs`, and the activity in
their audience. Both failures answer the same 404, so a guardian cannot probe
which children or activities exist. (`EventRsvpService` does not check the
child today; this change does not copy that gap.)

### D6: A store class instead of OpenRegister calls in every service

`EventFeedReader`, `EventRsvpService` and `EventSignupService` each carry their
own `findAll`, `normalise` and `objectService` plumbing. Three more copies
would be the same code four more times. `ActivityStore` holds it once; a read
failure returns null, never an empty list, so a count can fail closed.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Why |
|---|---|---|
| Activity status draft, open, closed | imperative (`ActivityController`) | Opening is guarded by the places rule (a lifecycle guard, ADR-031 exception), and matches `schoolEvent`'s publish |
| Places from capacity and ratio | imperative (`ActivitySignupService::placesFor`) | A domain rule selector used inside the sign-up decision, not a stored derived field |
| Confirm or waitlist on sign-up; promotion | imperative (`ActivitySignupService`) | Counts other rows before writing; not shaped for `x-openregister-aggregations` |
| Attendance upsert | imperative (`ActivityAttendanceService`) | Find by activity, session and child, then update or create: the same exception as the RSVP upsert |

No notifications in this change.

## Mixed-spec rationale

Schemas and code land together, as `events-and-signups` did: the schemas have
no reader without the services, and the services have nothing to read without
the schemas. Splitting would ship a register nobody can use.

## Nextcloud Integration

- Controllers: `ActivityController` (`#[NoAdminRequired]` + a
  `requireAuthenticatedStaff()` guard on every method, like `EventController`),
  `ActivityGuardianController` (`PortalProtected`, `#[PublicPage]`,
  `#[NoCSRFRequired]`, `#[AnonRateLimit]`).
- Services: `ActivityStore`, `ActivityPlaces`, `ActivityDraft`,
  `ActivityFeedReader`, `ActivitySignupService`, `ActivityAttendanceService`; reused `GuardianAudienceFixtureReader`,
  `NewsAudienceMatcher`, `PortalSessionService`, `IUserSession`,
  `ITimeFactory`.

## Security Considerations

- Guardian writes re-verify the activity is in the caller's audience and the
  child is the caller's own before anything is read or written; failures are
  one 404.
- Places are counted on the server right before the write; a failed read
  refuses with 502 rather than guessing.
- `activitySignup` and `activityAttendance` hold children's data: their
  OpenRegister read rule is `admin` only. The app reads them with RBAC off as
  the trusted scoper, exactly as the event services do. `activityOffer` reads
  like `schoolEvent` (`authenticated`). No write grants (RegisterAuthorizationTest).
- Staff posture is the current one for school communication (any Nextcloud
  user); proposal Risk 2.
- Race: two sign-ups for the last place can both pass the count (proposal
  Risk 1); the roster shows the over-fill.

## File Structure

```
lib/Controller/ActivityController.php
lib/Controller/ActivityGuardianController.php
lib/Service/ActivityStore.php
lib/Service/ActivityFeedReader.php
lib/Service/ActivitySignupService.php
lib/Service/ActivityAttendanceService.php
lib/Service/ActivityPlaces.php               places, confirmed, waiting-list order (pure)
lib/Service/ActivityDraft.php                validates and sanitises a new activity (pure)
lib/Settings/portaliq_register.json          activityOffer, activitySignup, activityAttendance; 0.34.0; seed objects
lib/Settings/portaliq_mock_register.json     three demo objects per schema
l10n/en.json, l10n/nl.json, l10n/*.js        schema strings
appinfo/routes.php
tests/Unit/Service/ActivityStoreTest.php
tests/Unit/Service/ActivityFeedReaderTest.php
tests/Unit/Service/ActivitySignupServiceTest.php
tests/Unit/Service/ActivityAttendanceServiceTest.php
tests/Unit/Service/ActivityDraftTest.php
tests/Unit/Service/InMemoryActivityStore.php         test double: the real store logic over memory
docs/operations/term-long-activities.md
tests/Unit/Controller/ActivityControllerTest.php
tests/Unit/Controller/ActivityGuardianControllerTest.php
tests/Unit/Settings/PortaliqRegisterConfigTest.php   version pin
```

## Seed Data

Seed rows in the main register tie to the existing fixtures: guardian
`guardian-anna-devries` (child `child-devries-lars`, group `groep-5a`, school
`school-de-regenboog`), guardian `guardian-piet-bakker` (children
`child-bakker-eva`, `child-bakker-tim`) and staff `staff-leerkracht-5a`,
`staff-leerkracht-3b`. Seed and demo rows point at activities by slug, which
`ActivityStore::keys()` accepts as an alias of the id. Three demo objects per
schema go into the mock register.

### Schema: `activityOffer`

| Field | Object 1 | Object 2 | Object 3 (mock only) |
|---|---|---|---|
| slug | `activity-schaakclub-najaar` | `activity-schoolzwemmen-groep-5` | `activityoffer-natuurexcursies` |
| title | Schaakclub groep 5 tot en met 8 | Schoolzwemmen groep 5 | Natuurexcursies in het najaar |
| kind | club | sport | trip |
| target | school `school-de-regenboog` | group `groep-5a` | groups `groep-3b`, `groep-4c` |
| termStart / termEnd | 2026-10-05 / 2026-12-14 | 2026-10-07 / 2027-01-27 | 2026-10-09 / 2026-11-20 |
| capacity | 16 | 24 | 30 |
| supervisorRefs | `staff-leerkracht-5a` | `staff-leerkracht-5a`, `staff-leerkracht-3b` | `staff-leerkracht-3b`, `staff-leerkracht-4c` |
| childrenPerSupervisor | 12 | 10 | 8 |
| places | 12 | 20 | 16 |
| waitlistEnabled | true | true | false |
| paymentRequested | false | true | true |
| status | open | open | draft |
| sessions | three Mondays 15:15 to 16:15 | three Wednesdays 13:00 to 14:00 | three Fridays 09:00 to 12:00 |

### Schema: `activitySignup`

| Field | Object 1 | Object 2 | Object 3 (mock only) |
|---|---|---|---|
| activity | schaakclub | schaakclub | schoolzwemmen |
| childRef | `child-devries-lars` | `child-bakker-tim` | `child-devries-lars` |
| guardianRef | `guardian-anna-devries` | `guardian-piet-bakker` | `guardian-anna-devries` |
| status | confirmed | waitlisted | confirmed |
| paymentRequestRef | none | none | `00000000-0000-0000-0000-000000000000` |

### Schema: `activityAttendance`

| Field | Object 1 | Object 2 (mock only) | Object 3 (mock only) |
|---|---|---|---|
| activity / session | schaakclub / week-1 | schaakclub / week-2 | schoolzwemmen / week-1 |
| childRef | `child-devries-lars` | `child-devries-lars` | `child-devries-lars` |
| status | present | excused | present |
| markedByRef | `staff-leerkracht-5a` | `staff-leerkracht-5a` | `staff-leerkracht-3b` |

## Trade-offs

- No conditional write in OpenRegister, so the last-place race stays
  (Risk 1), the same as event sign-up roles.
- The feed reads up to 500 rows per schema, like the event feed. Enough for
  one school; a paged read is a later concern.

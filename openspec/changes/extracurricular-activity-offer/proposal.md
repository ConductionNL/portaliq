---
kind: code
---

# Proposal: extracurricular-activity-offer

## Summary

Adds an activity a school runs for a whole term: a club, a course, a series of
trips. Staff set the places, the sessions, the supervisors and how many
children one supervisor may take. A guardian signs a child up from the portal;
the child gets a place or a spot on the waiting list, and a place that frees up
goes to the next child in line. Staff mark attendance per session. There is no
fee field: a place that costs money carries a reference to the shillinq payment
request for it (D19).

## Motivation

Learniq round 2, recon E (`learniq-mi/learniq/_round2/recon/E-roles-and-lesson-shop.md`),
section 1b, measured on portaliq `development`:

- "Extracurricular activity as its own object (club/offer with a roster,
  distinct from a one-off calendar event)": **missing**. `schoolEvent` is one
  start and end, with no term-long roster.
- "Waiting list once a sign-up role is full": **missing**.
  `EventSignupService::attemptSignup()` returns a flat refusal.
- "Attendance tracking on an event or activity": **missing**.
- "Supervisor/chaperone assignment with a ratio": **missing**. A
  `signupRoles` entry is a free-text label for guardian volunteers, with no
  staff link and no ratio.

Market, recon E section 2: `G-new-8` "Extracurricular activities: enrolment,
choices, waitlist, staffing and payment" (Gibbon Activities module),
`RS-new-3` (RosarioSIS activity eligibility and teacher completion), and the
scored rows 9.6 "Agenda and events per group with sign-up" and 9.8
"Activiteitenplanner and ouderhulp sign-ups" (Social Schools, Klasbord, Kwieb,
ParnasSys), both rung 4, tier A.

Decisions: D1 puts sign-ups and everything a guardian answers in portaliq. D19
makes a school contribution a shillinq invoice paid from portaliq, so this
object holds no amount. Recon E section 5 question 2 recommends option B: a new
object for term-long activities, leaving `schoolEvent` for one-off items.

## Affected Projects

- [x] Project: `portaliq`: schemas `activityOffer`, `activitySignup`,
  `activityAttendance`; staff and guardian endpoints; the sign-up, waiting
  list and attendance services; seed and demo data.

## Scope

### In Scope

- `activityOffer`: title, kind, target (school, groups, children), term
  dates, sign-up deadline, capacity, waiting list on or off, sessions,
  supervisor references, children per supervisor, whether a payment is
  requested, status (draft, open, closed).
- Places: the lower of `capacity` and supervisors times children per
  supervisor. An activity cannot open without room for at least one child.
- `activitySignup`: one child, signed up by one of their guardians:
  confirmed, waitlisted or withdrawn, plus the shillinq payment request
  reference for the place.
- Guardian: read the open activities in their audience with their own
  children's status and the places left; sign up one of their own children;
  withdraw. A freed place goes to the longest-waiting child.
- Staff: create, open, close, change supervisors (more supervisors promote
  from the waiting list), read the roster, mark attendance per session.
- `activityAttendance`: present, absent or excused, per session and child,
  for a child with a confirmed place.

### Out of Scope

- Parental consent on a sign-up: the next change, `activity-parental-consent`.
- Raising the payment request itself: shillinq's `extracurricular-fee-to-shillinq`
  (plan wave 2, lane L8) writes `paymentRequestRef`. Portaliq only stores it.
- Screens. Like `events-and-signups`, this ships the API; the staff and portal
  screens for school communication come as one later change.
- Eligibility against grades or attendance (`RS-new-3`).

## Approach

Same shape as `events-and-signups`: OpenRegister schemas in the `portaliq`
register, a staff controller behind a Nextcloud session, a guardian controller
behind the portal bearer, and small services. One store class wraps the
OpenRegister calls so the rules can be tested without it.

## New Dependencies

None.

## Impact

- `lib/Settings/portaliq_register.json` (three schemas, register 0.34.0),
  `lib/Settings/portaliq_mock_register.json` (demo objects), `l10n/`.
- New `ActivityController`, `ActivityGuardianController`, `ActivityStore`,
  `ActivityPlaces`, `ActivityDraft`, `ActivityFeedReader`,
  `ActivitySignupService`, `ActivityAttendanceService`.
- `appinfo/routes.php`: nine routes under `/api/activities`.

## Cross-Project Dependencies

- shillinq: `PaymentRequest` (register.d `ar-invoice-payment-links`) is the
  target of `activitySignup.paymentRequestRef`. Nothing is called.
- learniq: none. The guardian audience still comes from portaliq's interim
  `guardianAudienceFixture`, the same seam news and events use.

## Risks

### Risk 1: Two guardians take the last place at the same moment
**Severity:** Medium. **Mitigation:** the count runs right before the write, as
in `EventSignupService`. OpenRegister has no conditional write, so a race can
over-fill by one; the roster shows it and staff can move a child back. Named
in design.md.

### Risk 2: Any Nextcloud user counts as staff
**Severity:** Medium. **Mitigation:** the same posture as `EventController`
and `NewsController` today. A staff role model for school communication is a
separate change across news, events and activities.

### Risk 3: The audience fixture cannot tell which group a child is in
**Severity:** Low. **Mitigation:** a guardian may sign up only their own
children for an activity in their audience. When learniq's real collection
replaces the fixture, the child's own group can narrow it.

## Rollback Strategy

Revert the PR. The three schemas are new, so no existing data changes; rows
created in the meantime stay in OpenRegister, unreferenced.

## Open Questions

- Should a payment reference sit on the activity or on the place? A shillinq
  payment request has one debtor, so this change puts it on the sign-up and
  keeps a `paymentRequested` flag on the activity. Provisional decision:
  per place.

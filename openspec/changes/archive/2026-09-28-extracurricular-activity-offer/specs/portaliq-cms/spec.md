---
status: proposed
---

# Spec: portaliq-cms (extracurricular activities)

## ADDED Requirements

### Requirement: A term-long activity MUST be offered with places set by capacity and supervision

An `activityOffer` schema (register `portaliq`) SHALL carry `title`, `kind`
(`club`, `sport`, `culture`, `trip`, `course`, `other`), the `target` shape
`schoolEvent` uses, `termStart`, optional `termEnd` and `signupDeadline`,
`capacity` (at least 1), `waitlistEnabled`, `sessions[]` (`{id, start, end?,
location?}`), `supervisorRefs[]`, `childrenPerSupervisor`, `paymentRequested`
and `status` (`draft`, `open`, `closed`). It SHALL NOT carry an amount; a place
that costs money SHALL be paid through a shillinq payment request (D19). The
places of an activity SHALL be the lower of `capacity` and the number of
distinct supervisors times `childrenPerSupervisor` (capacity alone when
`childrenPerSupervisor` is 0 or absent). Staff SHALL NOT be able to open an
activity that has no place.

#### Scenario: Supervision caps the places
@e2e exclude {a derived number on the server; asserted in tests/Unit/Service/ActivitySignupServiceTest.php::testPlacesAreTheLowerOfCapacityAndSupervision}

- **GIVEN** an activity with `capacity: 20`, two supervisors and `childrenPerSupervisor: 8`
- **WHEN** its places are computed
- **THEN** the activity SHALL have 16 places

#### Scenario: An activity without enough supervision does not open
@e2e exclude {staff API refusal with no screen in this change; asserted in tests/Unit/Controller/ActivityControllerTest.php::testOpenRefusesAnActivityWithNoPlace}

- **GIVEN** a draft activity with `childrenPerSupervisor: 8` and no supervisors
- **WHEN** staff open it
- **THEN** the answer SHALL be 422 `no_places` and the activity SHALL stay a draft

### Requirement: A guardian MUST be able to sign up one of their own children, with a waiting list when full

A guardian SHALL see every non-draft activity in their audience, each with the
places left and their own children's sign-ups (never another guardian's). A
guardian SHALL be able to sign up one of their own children for an open
activity before its `signupDeadline`. The child SHALL get a confirmed place
while places remain, a waiting-list position when the activity is full and
`waitlistEnabled` is true, and a 422 `activity_full` otherwise. A child with a
sign-up that is not withdrawn SHALL NOT be signed up twice (409). An activity
outside the guardian's audience and a child who is not the guardian's own
SHALL both answer the same 404, with nothing written. A closed activity or a
passed deadline SHALL answer 422 `signup_closed`.

#### Scenario: The last place, then the waiting list
@e2e exclude {needs two guardian sessions against a live portal; asserted in tests/Unit/Service/ActivitySignupServiceTest.php::testAFullActivityWaitlistsInOrder}

- **GIVEN** an open activity with one place left and `waitlistEnabled: true`
- **WHEN** two guardians each sign up a child, one after the other
- **THEN** the first child SHALL be confirmed
- **AND** the second SHALL be waitlisted at position 1

#### Scenario: Another guardian's child is refused like an unknown activity
@e2e exclude {an absence of access, observable only at the seam; asserted in tests/Unit/Service/ActivitySignupServiceTest.php::testAChildWhoIsNotTheGuardiansOwnIsRefused}

- **GIVEN** an open activity in the guardian's audience
- **WHEN** the guardian signs up a child who is not in their own children
- **THEN** the refusal SHALL be the same as for an activity that does not exist
- **AND** no sign-up SHALL be written

### Requirement: A freed place MUST go to the child who waited longest

When a confirmed sign-up is withdrawn, or staff add supervisors so the places
grow, the service SHALL confirm waitlisted children in the order they signed
up until the places are full. Withdrawing a waitlisted sign-up SHALL promote
nobody. A guardian SHALL be able to withdraw only a sign-up of one of their own
children.

#### Scenario: A withdrawal promotes the first child on the list
@e2e exclude {an ordering rule on stored rows; asserted in tests/Unit/Service/ActivitySignupServiceTest.php::testAWithdrawalPromotesTheLongestWaitingChild}

- **GIVEN** a full activity with two waitlisted children, the older sign-up first
- **WHEN** a guardian withdraws a confirmed child
- **THEN** the older waitlisted child SHALL be confirmed and the other SHALL stay waitlisted

#### Scenario: More supervisors make more places
@e2e exclude {staff API with no screen in this change; asserted in tests/Unit/Service/ActivitySignupServiceTest.php::testMoreSupervisorsPromoteFromTheWaitingList}

- **GIVEN** a full activity with one supervisor, `childrenPerSupervisor: 2` and three waitlisted children
- **WHEN** staff set two supervisors
- **THEN** two waitlisted children SHALL be confirmed in sign-up order

### Requirement: Staff MUST be able to mark attendance per session

Staff SHALL be able to mark a child `present`, `absent` or `excused` for one of
the activity's declared sessions, only for a child with a confirmed place. A
second mark for the same session and child SHALL update the first. An unknown
session, a child without a confirmed place and an unknown status SHALL each be
refused with 422 and nothing written.

#### Scenario: A second mark corrects the first
@e2e exclude {an upsert invariant on stored rows; asserted in tests/Unit/Service/ActivityAttendanceServiceTest.php::testASecondMarkUpdatesTheFirst}

- **GIVEN** a child marked `absent` for session `week-3`
- **WHEN** staff mark the same child `present` for `week-3`
- **THEN** exactly one attendance row SHALL exist for that child and session, reading `present`

#### Scenario: A waitlisted child cannot be marked present
@e2e exclude {a refusal on the staff API; asserted in tests/Unit/Service/ActivityAttendanceServiceTest.php::testOnlyAConfirmedChildInADeclaredSessionIsMarked}

- **GIVEN** a waitlisted child
- **WHEN** staff mark that child present
- **THEN** the answer SHALL be 422 `not_confirmed` and nothing SHALL be written

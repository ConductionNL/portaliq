---
status: proposed
---

# Spec: portaliq-cms (paying for an activity place)

## ADDED Requirements

### Requirement: Staff MUST be able to raise the contribution for an activity's confirmed places, and portaliq MUST write the reference

`POST /apps/portaliq/api/activities/{id}/contributions` SHALL need a Nextcloud
session (403 without one). It SHALL answer 404 for an unknown activity and 422
`payment_not_requested` when the activity's `paymentRequested` is not true. The
body SHALL carry `amount` (above zero), `voluntary` (boolean) and
`administrationId`, and MAY carry `description` (default: the activity title),
`invoiceDate`, `dueDate`, `revenueAccount` and `language`; a body without the
three SHALL answer 400 `invalid_charge` with no call to shillinq. Portaliq
SHALL raise through shillinq's `ContributionRaiseService::raise()` one recipient
per `confirmed` sign-up of the activity that has no `paymentRequestRef`, in
chunks of at most 200, with the chargeable `{app: portaliq, type:
activity-offer, register: portaliq, schema: activityOffer, id: <activity id>}`,
`kind: activity`, the debtor `{portalSubjectRef: guardianRef, name, email}` read
from the guardian's `portalAccount`, and the beneficiary `{type: learner, id:
childRef}`. A sign-up whose guardian account has no name or email SHALL be
reported `failed` with `no_contact_details` and SHALL NOT be sent. For every
result that is `raised` or `skipped` with a `paymentRequestId`, portaliq SHALL
write that id into the sign-up's `paymentRequestRef`. Portaliq SHALL NOT store
the amount. Without shillinq the answer SHALL be 503 `shillinq_unavailable`;
shillinq's refusal of the staff member SHALL be 403 `forbidden`; shillinq's
refusal of the charge SHALL be 400 `invalid_charge`; any other failure SHALL be
502 `raise_failed`.

#### Scenario: Staff raise the club fee and each place gets its reference
@e2e exclude {needs shillinq installed with a payment action for the staff member; asserted in tests/Unit/Service/ActivityContributionServiceTest.php::testConfirmedPlacesAreRaisedAndReferenced}

- **GIVEN** an activity with `paymentRequested: true`, two confirmed places, one waitlisted place and one confirmed place that already has a reference
- **WHEN** staff raise it with `amount: 25`, `voluntary: true` and `administrationId: adm-school-1`
- **THEN** shillinq SHALL receive two recipients, each with the activity as chargeable, the child as beneficiary and the guardian as debtor
- **AND** both sign-ups SHALL carry the returned `paymentRequestId` in `paymentRequestRef`

#### Scenario: A repeated raise bills nobody twice
@e2e exclude {shillinq's idempotency answer; asserted in tests/Unit/Service/ActivityContributionServiceTest.php::testASkippedResultStillWritesTheStandingReference}

- **GIVEN** a place shillinq already billed, whose reference write failed last time
- **WHEN** staff raise again
- **THEN** shillinq SHALL answer `skipped` with the standing request, and portaliq SHALL write that id into the sign-up

#### Scenario: A guardian without contact details is reported, the rest are raised
@e2e exclude {a data-quality branch; asserted in tests/Unit/Service/ActivityContributionServiceTest.php::testAGuardianWithoutContactDetailsIsReportedAndNotSent}

- **GIVEN** two confirmed places, one guardian account without an email
- **WHEN** staff raise
- **THEN** only the other place SHALL be sent, and the answer SHALL report the first as `failed` with `no_contact_details`

#### Scenario: No contribution is asked, or shillinq is missing
@e2e exclude {refusals before any call; asserted in tests/Unit/Controller/ActivityControllerTest.php::testContributionsMapsEachRefusal}

- **GIVEN** an activity with `paymentRequested: false`, or an instance without shillinq
- **WHEN** staff raise
- **THEN** the answer SHALL be 422 `payment_not_requested`, or 503 `shillinq_unavailable`, and no sign-up SHALL change

---
kind: code
---

# Proposal: activity-offer-contract-fix

## Summary

Portaliq #746 (`extracurricular-activity-offer`) wrote in its contract that
shillinq raises a payment request per confirmed place and writes the reference
into `activitySignup.paymentRequestRef`. Shillinq does not write into another
app's register; its contributions raise (#1704) answers with the ids instead.
So portaliq raises the contribution itself, for the confirmed places of a
fee-bearing activity, and writes `paymentRequestRef` from shillinq's answer.
The chargeable is the `activityOffer`, the child is the beneficiary, the
guardian who signed up is the debtor. Portaliq still holds no amount: staff
type it into the raise, and it goes straight to shillinq.

## Motivation

- Shillinq's contract (`openspec/changes/extracurricular-fee-to-shillinq/contract.md`
  on shillinq `development` `2a9d5876e`, Consumers): "`portaliq`
  (`extracurricular-activity-offer`): raises the contribution of an
  `activityOffer` for the guardians of its confirmed places, and writes its own
  `activitySignup.paymentRequestRef` from the raise response."
- Portaliq's contract (`openspec/changes/extracurricular-activity-offer/contract.md`,
  Consumers and the data table) says the opposite: "Written by shillinq, read by
  portaliq", with the payment request's `subject` being the sign-up.
- Nothing in portaliq calls shillinq today (`git grep -i shillinq lib/` names
  only descriptions), so no activity place can be paid.
- Decisions D19 and D30: contributions are shillinq invoices paid from the
  portal. The pay screen (`contribution-pay-screen`) needs the invoices to exist.

## Affected Projects

- [x] Project: `portaliq`: the raise endpoint, the reference write, the
  contract, two schema descriptions, docs.

## Scope

### In Scope

- `POST /apps/portaliq/api/activities/{id}/contributions` (staff): raise the
  contribution for every confirmed place that has no `paymentRequestRef` yet,
  through shillinq's in-process `ContributionRaiseService::raise()`, and write
  each returned `paymentRequestId` into its sign-up.
- The raise payload: chargeable `{app: portaliq, type: activity-offer, register:
  portaliq, schema: activityOffer, id}`, `kind: activity`, debtor
  `{portalSubjectRef, name, email}` from the guardian's `portalAccount`,
  beneficiary `{type: learner, id: childRef}`.
- A retried or repeated raise bills nobody twice: shillinq answers `skipped`
  with the standing request, and portaliq writes that reference too.
- The #746 contract, the two schema descriptions and the docs say who writes
  what.

### Out of Scope

- Any amount stored in portaliq. The amount is a request parameter passed on.
- Raising automatically when a place is confirmed (a waitlist promotion): staff
  run the raise again, which picks up new places only. An automatic raise needs
  a staff session shillinq can check, which a guardian's sign-up does not have.
- The settled signal: portaliq does not need it; the pay screen reads the
  invoice state from shillinq.

## Approach

A small adapter holds the duck-typed shillinq call (`class_exists`, the
container, the exception mapping). A service builds the recipients, calls the
adapter in chunks of 200, and writes the references. The staff controller
guards and maps. See design.md.

## New Dependencies

None. Shillinq stays optional: without it the endpoint answers 503.

## Impact

- New `lib/Service/ActivityContributionService.php`,
  `lib/Service/ShillinqContributionRaiser.php`; `ActivityController` gains
  `contributions()`; one route.
- `lib/Settings/portaliq_register.json`: descriptions of
  `activityOffer.paymentRequested` and `activitySignup.paymentRequestRef`,
  schema versions and `info.version`; two catalogue keys.
- `openspec/changes/extracurricular-activity-offer/contract.md` amended.

## Cross-Project Dependencies

- Consumes shillinq's contributions raise (contract version 1). The staff
  member needs shillinq's `payment.request` action (`paymentActionGroups`).
- The pay screen (`contribution-pay-screen`, portaliq #805) shows the resulting
  invoices to the guardian.

## Risks

### Risk 1: A guardian without a name or email on their portal account
**Severity:** Medium. **Mitigation:** shillinq needs both next to
`portalSubjectRef`. Portaliq reports that place as `failed` with
`no_contact_details` and raises the others; staff fix the account and run the
raise again.

### Risk 2: The reference write fails after shillinq raised
**Severity:** Low. **Mitigation:** the next raise answers `skipped` with the
same request id and portaliq writes it then.

## Rollback Strategy

Revert the PR. No data migrates; written references stay valid shillinq ids.

# Design: activity-offer-contract-fix

Read at portaliq `development` `db4a6cf` and shillinq `development`
`2a9d5876e` (shillinq #1704, contract version 1).

## Architecture overview

```
staff (Nextcloud session, shillinq payment.request action)
  -> POST /apps/portaliq/api/activities/{id}/contributions {amount, voluntary, administrationId, ...}
     ActivityController::contributions()  (requireAuthenticatedStaff)
       -> ActivityContributionService::raise()
            activity (404) -> paymentRequested (422) -> charge (400)
            confirmed sign-ups without paymentRequestRef
            guardian portalAccount -> {portalSubjectRef, name, email}   (else failed: no_contact_details)
            chunks of 200 -> ShillinqContributionRaiser::raise()
                               class_exists + Server::get(ContributionRaiseService)->raise($payload)
            results raised|skipped -> ActivityStore::save(activitySignup, paymentRequestRef)
       <- {raised, skipped, failed, results[]}
```

## Decisions

### D1. Portaliq writes the reference from the raise response

Shillinq's contract makes the owning app write its own reference, and it
answers per recipient with `paymentRequestId` for both `raised` and `skipped`.
Portaliq maps results back by `index` within the chunk and saves the sign-up
with `paymentRequestRef`. The payment request's `subject` is now the activity
(the chargeable), and its `beneficiary` is the child, as shillinq's contract
defines them; #746 had the sign-up as subject.

### D2. The in-process call, behind an adapter

Shillinq's contract offers the same call in process for an app already inside
the request. The staff request is such a request, and the in-process call
checks `payment.request` against the same session user, so no second
credential is needed. The duck-typed lookup (`class_exists`,
`\OCP\Server::get()`) and the exception mapping live in
`ShillinqContributionRaiser`, so the service is tested with a plain double:

| Shillinq | Raiser answer | HTTP |
|---|---|---|
| class missing | `shillinq_unavailable` | 503 |
| `InvalidArgumentException` | `invalid_charge` | 400 |
| `RuntimeException` starting `403` | `forbidden` | 403 |
| anything else | `raise_failed` | 502 |

Alternative considered: the HTTP route. Rejected: it needs the staff member's
CSRF token relayed server-side for nothing the in-process call lacks.

### D3. Staff trigger it; confirmed places without a reference only

A raise runs when staff ask for it, with the amount they type. It picks the
`confirmed` sign-ups of the activity with an empty `paymentRequestRef`, so a
second run after a waitlist promotion bills only the new places, and a run
after a failed write is healed by shillinq's `skipped` answer. Waitlisted and
withdrawn places are never billed.

Alternative considered: raise on each confirmation. Rejected for now: a
guardian's sign-up has no Nextcloud user, so shillinq's `payment.request` check
has no one to check; it would need a system context shillinq does not offer.

### D4. The debtor from the guardian's own portal account

`guardianRef` is the guardian's portal `subjectRef`. `PortalAccountLookup::bySubjectRef()`
gives `displayName` and `email` (else `verifiedEmail`). Shillinq resolves the
subject to a customer and links its `customerMasterId` claim, which is what the
guardian's `parent` view of shillinq scopes on. A missing name or email is
reported before the call.

### D5. No amount in portaliq

The amount, the voluntary flag and the administration are request parameters
passed on to shillinq and not saved on any portaliq row. The schema keeps
`paymentRequested` as the only payment field on the activity.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Why |
|---|---|---|
| Raise per confirmed place | Imperative service | External integration with per-recipient results (ADR-031 exception), triggered by staff |
| Write the reference | Imperative, in the same service | The value comes from the external answer |

## API design

`POST /apps/portaliq/api/activities/{id}/contributions`

```json
{ "amount": 25.0, "voluntary": true, "administrationId": "adm-school-1", "description": "Schaakclub najaar", "dueDate": "2026-11-01" }
```

```json
{
  "raised": 1, "skipped": 1, "failed": 1,
  "results": [
    { "signupId": "00000000-0000-0000-0000-000000000021", "childRef": "child-devries-lars", "status": "raised", "paymentRequestRef": "00000000-0000-0000-0000-000000000011" },
    { "signupId": "00000000-0000-0000-0000-000000000022", "childRef": "child-bakker-sem", "status": "skipped", "paymentRequestRef": "00000000-0000-0000-0000-000000000013" },
    { "signupId": "00000000-0000-0000-0000-000000000023", "childRef": "child-yilmaz-noor", "status": "failed", "reason": "no_contact_details" }
  ]
}
```

## Database changes

None. Two schema descriptions change (activityOffer 0.2.1, activitySignup
0.2.1, register 0.35.2); no property is added or removed.

## Security considerations

- Staff guard first; shillinq checks `payment.request` itself.
- The amount never comes from a guardian; guardian routes are unchanged.
- Only the activity's own confirmed sign-ups are billed; the debtor is the
  guardian who signed up, read from portaliq's own account row.

## File structure

```
lib/Service/ShillinqContributionRaiser.php     new
lib/Service/ActivityContributionService.php    new
lib/Controller/ActivityController.php          contributions()
appinfo/routes.php                             one route
lib/Settings/portaliq_register.json            descriptions, versions
l10n/en.json, l10n/nl.json (+ .js)             two catalogue keys
openspec/changes/extracurricular-activity-offer/contract.md  amended
docs/operations/term-long-activities.md        who raises, who writes
```

## Seed data

No new schema. The existing `activitySignup` demo row with a
`paymentRequestRef` stays valid (a nil-UUID reference).

## Risks / trade-offs

- [A promoted place is not billed until staff run the raise again] → the
  roster shows which confirmed places have no reference; docs say to run it
  after promotions.

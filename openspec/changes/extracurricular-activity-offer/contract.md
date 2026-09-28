# Contract: extracurricular-activity-offer

## Consumers

- `shillinq` (`extracurricular-fee-to-shillinq`, shillinq #1704): portaliq calls
  its contributions raise (`ContributionRaiseService::raise()`, in process) for
  the confirmed places of an activity with `paymentRequested: true`, and writes
  each returned `paymentRequestId` into `activitySignup.paymentRequestRef`
  itself. Shillinq does not write into portaliq's register. Amended by
  `activity-offer-contract-fix`; this contract said the opposite before.
- The portal SPA and a later staff screen: call the endpoints below. No other
  app calls them.

## Data shared with shillinq

| Object | Field | Meaning |
|---|---|---|
| `portaliq/activityOffer` | `paymentRequested` (bool) | This activity asks a contribution per place. The amount lives in shillinq. |
| `portaliq/activitySignup` | `status` | `confirmed` is the only status a payment request is raised for. |
| `portaliq/activitySignup` | `guardianRef` | The guardian who signed up: the debtor, sent as `{portalSubjectRef, name, email}` from their `portalAccount`. |
| `portaliq/activitySignup` | `childRef` | The child: the beneficiary, sent as `{type: learner, id: childRef}`. |
| `portaliq/activitySignup` | `paymentRequestRef` | UUID of `shillinq/PaymentRequest`. Its `subject` is the activity (`{app: portaliq, type: activity-offer, register: portaliq, schema: activityOffer, id}`), its `beneficiary` the child. Written by portaliq from the raise answer (`raised` or `skipped`), read by portaliq. |

Portaliq never computes, stores or shows an amount: staff type it into the
raise and it goes straight to shillinq. The guardian pays from shillinq's
`parent` view in the portal (D12, D19, D30; portaliq `contribution-pay-screen`).

## Endpoints

Staff endpoints need a Nextcloud session (`#[NoAdminRequired]`, guarded like
`EventController`). Guardian endpoints need a portal bearer (`PortalProtected`,
`#[PublicPage]`, rate limited).

### Staff

| Method | Path | Body | Success | Errors |
|---|---|---|---|---|
| POST | `/apps/portaliq/api/activities` | `title`, `kind`, `target`, `termStart`, `capacity`, optional `termEnd`, `signupDeadline`, `sessions`, `supervisorRefs`, `childrenPerSupervisor`, `waitlistEnabled`, `paymentRequested`, `description`, `location`, `authorRef` | 200 the draft | 400 `invalid_activity`, 403, 502 `write_failed` |
| PUT | `/apps/portaliq/api/activities/{id}/open` | | 200 the activity | 403, 404, 422 `no_places`, 502 `write_failed` |
| PUT | `/apps/portaliq/api/activities/{id}/close` | | 200 the activity | 403, 404, 502 `write_failed` |
| PUT | `/apps/portaliq/api/activities/{id}/supervisors` | `supervisorRefs` | 200 `{activity, promoted}` | 403, 404 |
| GET | `/apps/portaliq/api/activities/{id}/roster` | | 200 `{places, confirmed[], waitlist[]}` | 403, 404 |
| POST | `/apps/portaliq/api/activities/{id}/contributions` | `amount`, `voluntary`, `administrationId`, optional `description`, `invoiceDate`, `dueDate`, `revenueAccount`, `language`, `currency` | 200 `{raised, skipped, failed, results[{signupId, childRef, status, paymentRequestRef?, reason?}]}` | 400 `invalid_charge`, 403 / 403 `forbidden`, 404, 422 `payment_not_requested`, 502 `activity_unavailable` / `raise_failed`, 503 `shillinq_unavailable` |
| PUT | `/apps/portaliq/api/activities/{id}/attendance` | `sessionId`, `childRef`, `status` | 200 the attendance row | 403, 404, 422 `unknown_session` / `not_confirmed` / `invalid_status`, 502 `unavailable` |

### Guardian

| Method | Path | Body | Success | Errors |
|---|---|---|---|---|
| GET | `/apps/portaliq/api/activities/feed` | | 200 `[activity + placesLeft + mySignups[]]` | 401 |
| POST | `/apps/portaliq/api/activities/{id}/signup` | `childRef`, optional `note` | 200 `{status: confirmed}` or `{status: waitlisted, position}` | 401, 404, 409 `already_signed_up`, 422 `signup_closed` / `activity_full`, 502 `activity_unavailable` |
| POST | `/apps/portaliq/api/activities/{id}/withdraw` | `childRef` | 204 | 401, 404, 502 `activity_unavailable` |

`mySignups[]` entries: `{childRef, status, position?, paymentRequestRef?}`,
only for the calling guardian's own children.

## Error Codes

| Code | Meaning | Condition |
|---|---|---|
| 400 | Bad request | Missing title, target, termStart or a capacity below 1 |
| 401 | Unauthenticated | No portal bearer (guardian routes) |
| 403 | Forbidden | No Nextcloud session (staff routes, `OCSForbiddenException`) |
| 404 | Not found | Unknown activity, outside the audience, or not the guardian's own child |
| 409 | Conflict | The child already has a sign-up that is not withdrawn |
| 400 | Bad request | `invalid_charge`: a raise without `amount` above zero, a boolean `voluntary` and `administrationId`, or refused by shillinq as a whole |
| 422 | Unprocessable | `no_places`, `signup_closed`, `activity_full`, `unknown_session`, `not_confirmed`, `invalid_status`, `payment_not_requested` |
| 502 | Bad gateway | Rows could not be read or written: `write_failed`, `unavailable`, `activity_unavailable`; `raise_failed` |
| 503 | Unavailable | `shillinq_unavailable`: shillinq is not installed |

## Versioning

New schemas at version 0.1.0; register 0.34.0. Additive.

## Breaking Change Policy

Renaming or removing `paymentRequestRef`, `paymentRequested` or the
`confirmed` status breaks the pay screen and the raise; such a change lists
shillinq as a consumer. A change to shillinq's raise contract (version 1)
lands here first.

## SLA

Synchronous. Sign-up and withdraw read at most two schemas of at most 500 rows.

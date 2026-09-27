# Contract: extracurricular-activity-offer

## Consumers

- `shillinq` (`extracurricular-fee-to-shillinq`, plan wave 2, lane L8): raises a
  `PaymentRequest` per confirmed place when `activityOffer.paymentRequested` is
  true, and writes its UUID into `activitySignup.paymentRequestRef`.
- The portal SPA and a later staff screen: call the endpoints below. No other
  app calls them.

## Data shared with shillinq

| Object | Field | Meaning |
|---|---|---|
| `portaliq/activityOffer` | `paymentRequested` (bool) | This activity asks a contribution per place. The amount lives in shillinq. |
| `portaliq/activitySignup` | `status` | `confirmed` is the only status a payment request is raised for. |
| `portaliq/activitySignup` | `guardianRef` | The guardian who signed up: the debtor candidate. |
| `portaliq/activitySignup` | `paymentRequestRef` | UUID of `shillinq/PaymentRequest` (`subjectKind: object`, `subject` = the sign-up). Written by shillinq, read by portaliq. |

Portaliq never computes, stores or shows an amount. The pay screen is
shillinq's portal contribution (D12, D19).

## Endpoints

Staff endpoints need a Nextcloud session (`#[NoAdminRequired]`, guarded like
`EventController`). Guardian endpoints need a portal bearer (`PortalProtected`,
`#[PublicPage]`, rate limited).

### Staff

| Method | Path | Body | Success | Errors |
|---|---|---|---|---|
| POST | `/apps/portaliq/api/activities` | `title`, `kind`, `target`, `termStart`, `capacity`, optional `termEnd`, `signupDeadline`, `sessions`, `supervisorRefs`, `childrenPerSupervisor`, `waitlistEnabled`, `paymentRequested`, `description`, `location`, `authorRef` | 200 the draft | 400 `invalid_activity`, 500 |
| PUT | `/apps/portaliq/api/activities/{id}/open` | | 200 the activity | 404, 422 `no_places` |
| PUT | `/apps/portaliq/api/activities/{id}/close` | | 200 the activity | 404 |
| PUT | `/apps/portaliq/api/activities/{id}/supervisors` | `supervisorRefs` | 200 `{activity, promoted}` | 404 |
| GET | `/apps/portaliq/api/activities/{id}/roster` | | 200 `{places, confirmed[], waitlist[]}` | 404 |
| PUT | `/apps/portaliq/api/activities/{id}/attendance` | `sessionId`, `childRef`, `status` | 200 the attendance row | 404, 422 `unknown_session` / `not_confirmed` / `invalid_status` |

### Guardian

| Method | Path | Body | Success | Errors |
|---|---|---|---|---|
| GET | `/apps/portaliq/api/activities/feed` | | 200 `[activity + placesLeft + mySignups[]]` | 401 |
| POST | `/apps/portaliq/api/activities/{id}/signup` | `childRef`, optional `note` | 200 `{status: confirmed}` or `{status: waitlisted, position}` | 401, 404, 409 `already_signed_up`, 422 `signup_closed` / `activity_full`, 502 `activity_unavailable` |
| POST | `/apps/portaliq/api/activities/{id}/withdraw` | `childRef` | 204 | 401, 404 |

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
| 422 | Unprocessable | `no_places`, `signup_closed`, `activity_full`, `unknown_session`, `not_confirmed`, `invalid_status` |
| 502 | Bad gateway | Sign-ups could not be read, so no place can be promised |

## Versioning

New schemas at version 0.1.0; register 0.34.0. Additive.

## Breaking Change Policy

Renaming or removing `paymentRequestRef`, `paymentRequested` or the
`confirmed` status breaks shillinq's fee change; such a change lists shillinq
as a consumer and lands after shillinq's update.

## SLA

Synchronous. Sign-up and withdraw read at most two schemas of at most 500 rows.

# Design: identity-access-requests

Read at portaliq `development` `eeda3fa`.

## Where it sits today

- `lib/Controller/PortalAccountSelfController.php:171` `requestAccess(onBehalfOf, reason)`
  and `myAccessRequests()`, bearer-guarded, `AnonRateLimit`.
- `lib/Service/Identity/PortalAccessRequestService.php`: `request()` (:76)
  refuses an empty reason and writes `portalAccessRequest` with
  `state: pending`; `forOwner(organisation, state)` (:111); `madeBy(subjectRef)`
  (:150); `decide(id, organisation, granted, decidedBy)` (:190) writes the
  state, scoped by `organisation`. `forOwner()` and `decide()` have no caller.
- `portalAccessRequest` schema: `subjectRef`, `displayName`, `organisation`,
  `onBehalfOf`, `reason`, `state` (`pending`, `granted`, `refused`),
  `decidedBy`, `decidedAt`, `requestedAt`.
- `portalMandate` schema: `subjectRef`, `organisation`, `onBehalfOf`, `label`,
  `caseTypes`, `reach` (`organisation`, `tree`), `status`, `grantedBy`,
  `grantedAt`, `expiresAt`. No route writes one.
- `lib/Service/ActionAuthService.php:87` `requireAction()` over
  `lib/actions.seed.json` (three actions, all `['admin']`).
- The admin app is manifest-driven (`src/manifest.json`, pages of type
  `index` and `detail` over a schema, plus custom pages).

## D1. The owner answers through one controller

A new `lib/Controller/AccessRequestAdminController.php`, `#[NoAdminRequired]`,
guarded by `ActionAuthService::requireAction($user, 'portal.answer-access-request')`
in every method, like `PortalAccountAdminController`:

- `GET /api/access-requests?organisation=&state=pending` calls `forOwner()`.
- `POST /api/access-requests/{id}/grant` and `/refuse` (refuse needs a
  `reason`) call `decide()`.

The staff user's organisation must equal the request's; `decide()` already
re-reads the row scoped by `organisation`, so a foreign id answers 404.

## D2. A grant writes the mandate, in one place

`PortalAccessRequestService::grant()` wraps `decide(granted: true)` and then
writes a `portalMandate`: `subjectRef` of the asker, the request's
`organisation` and `onBehalfOf`, `reach: organisation`, `status: active`,
`grantedBy` the staff user, `label` "Granted on request". When the mandate
write fails, the request is set back to `pending` and the answer is an error,
so a request never reads `granted` without the mandate behind it.

`refuse()` stores the reason in a new `decisionReason` property on
`portalAccessRequest`, which the asker sees.

## D3. The asker's screens live on "My cases"

`src/portal/components/AccessRequests.jsx`: the form (two fields, "Whose cases
do you need?" and "Why do you need them?") and the list of your own requests
(`myAccessRequests()`), each with its state and, for a refusal, the reason. It
renders under the "My cases" page of `cases-my-cases-page`; until that page
exists it renders on "My account".

## D4. The staff screen is a manifest page

`src/manifest.json` gains `AccessRequests` (`/access-requests`), a custom
page over `AccessRequestAdminController`, because grant and refuse are actions,
not field edits. Grant and refuse open a dialog in `src/dialogs/` (the modal
isolation rule, ADR-004).

## Risks

- A grant opens an organisation's cases. It is an ADR-023 action, seeded to
  administrators, and every grant is recorded with who granted it.
- A request names a party by free text. Staff read the reason before granting;
  nothing is matched automatically.

## What it deliberately does not do

- It sends no mail on an answer. The asker sees the answer in the list; a mail
  is `inbox-notifications-and-preferences` territory.

# Design: portal-identity-space

Kind: code. One lifecycle state, one service method, two typed events,
one collection.

## D1. `portalAccount`

`status` gains `pending` beside `active` and `suspended`, declared as an
`x-openregister-lifecycle` with initial `pending` for provisioned rows
and `active` for login-created rows. New properties: `provisionedBy`
(`{app, userId}`), `provisionedAt`, `verifiedEmail` (boolean). `identityRef`
becomes optional; when `identityType` is `digid` the value is stored
through OpenRegister's `BsnFormat` (ADR-064).

## D2. `PortalAccountService::provision()`

`provision(audience, organisation, identityType, identityRef|null,
email|null, provisionedBy)`:

1. Refuse when neither `identityRef` nor `email` is given.
2. Find an existing account on `(identityType, identityRef, organisation)`
   and return it unchanged when found.
3. Otherwise create one in `pending` with a fresh `subjectRef`.

Callers: the `portal.provision` action for staff (ADR-023 matrix), the
typed event `PortalAccountProvisionRequestedEvent` (result slot carries
the `subjectRef` or a structured refusal), and the journey runtime when
a `journey.access` is `authenticated` and the run's subject is new.

## D3. Matching at login

`SessionController`'s callback path calls
`PortalAccountService::findOrCreateForLogin()`. The change inserts one
step: before creating, look up a `pending` account on `(identityType,
identityRef, organisation)`; when found, set `status: active`,
`lastLoginAt`, and reuse its `subjectRef`. When the broker envelope
carries a verified email and no identity match exists, match a `pending`
account whose `email` equals it and whose `verifiedEmail` is true. No
other matching.

## D4. Claims by the owning app

`PortalAccountClaimRequestedEvent(subjectRef, appId, claimName, value)`
with a result slot. Portaliq writes `claims.<appId>.<claimName>` on the
account server-side and answers `ok` or a refusal. The `appId` is taken
from the dispatching app's DI context, never from the payload, so an app
cannot write another app's claim.

## D5. "My cases"

A portal page for the `client` audience listing the collections the
contribution registry marks `kind: cases` (an optional manifest hint;
absent means the page lists every collection), each scoped by its
`scopeClaim`. A pending account that becomes active sees the rows at once,
because the claim was written before the login.

## D6. The token page

`#PublicStatus` and its shillinq and dossiq equivalents keep working. The
page shows "Log in to see all your cases" when the case's subject has a
`portalAccount`, and nothing about the account otherwise.

## Risks

- A wrong BSN typed at the desk. The account is pending and unreachable;
  the right person never matches it. A staff action "void pending account"
  with a reason removes it.
- Two organisations provisioning the same person. `organisation` is part
  of the key, as it is today.

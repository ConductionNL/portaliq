# Design: invitation-secret-joins-the-signed-in-account

## D1. The secret lives on the waiting account

Two fields on `portalAccount`: `claimTokenHash` (SHA-256) and `claimExpiresAt`. This is the shape of `activationTokenHash` and `pendingEmailTokenHash`, which sit on the account they act on. The existing `portalInvitation` record was not reused: it invites an address that has no account yet, and its `accept()` creates the waiting account. Here the waiting account already exists and carries the app's claim, so the secret belongs on it. Inviting again replaces the earlier secret.

## D2. The secret travels in the fragment and is kept in the tab

The mail's link ends in `#claim=<secret>`, like every other identity mail. A fragment never reaches a server access log. The site keeps the secret in `sessionStorage`, as it keeps a notification's `#open=` target, and hands it back after the sign-in. The secret is not put in the sign-in's return address: a query string would be written to access logs on the portal and on the broker.

## D3. One redeem route, for a signed-in session

`PortalAccountClaimController::redeem` is behind the bearer middleware. It checks the session's trust before it looks at the secret, so that refusal says nothing about any invitation. The receiving account is always the session's own.

## D4. Spent before the join

The hash is emptied before the claims are copied. If the copy then fails, the invitation is dead and the app invites again. A secret that could work twice would be worse.

## D5. Attempt limits that hold without a cache

`ClaimAttempts` counts wrong secrets on the caller's own account row (`claimAttempts`, `claimAttemptsSince`): five inside an hour lock the route for that account, whatever session it uses. The count is in OpenRegister, so it holds on an instance without a shared cache and across restarts. A second count per session sits in the distributed cache, and Nextcloud's own rate limit (10 a minute per address) is the outer floor. A lock answers 429, also for the right secret.

## D6. One answer for every dead secret

Unknown, expired, used, another organisation's, and a waiting account that is no longer waiting all answer `403 invitation_not_valid`.

## D7. Schema

`portalAccount` 0.14.0 to 0.15.0, register 0.58.0 to 0.59.0. Additive: four optional properties.

# Design: invitation-secret-joins-the-signed-in-account

## D1. The secret lives on the waiting account

Two fields on `portalAccount`: `claimTokenHash` (SHA-256) and `claimExpiresAt`. This is the shape of `activationTokenHash` and `pendingEmailTokenHash`, which sit on the account they act on. The existing `portalInvitation` record was not reused: it invites an address that has no account yet, and its `accept()` creates the waiting account. Here the waiting account already exists and carries the app's claim, so the secret belongs on it. Inviting again replaces the earlier secret.

## D2. The secret travels in the fragment and is kept in the tab

The mail's link ends in `#claim=<secret>`, like every other identity mail. A fragment never reaches a server access log. The site keeps the secret in `sessionStorage`, as it keeps a notification's `#open=` target, and hands it back after the sign-in. The secret is not put in the sign-in's return address: a query string would be written to access logs on the portal and on the broker.

## D3. One redeem route, for a signed-in session

`PortalAccountClaimController::redeem` is behind the bearer middleware. It checks the session's trust before it looks at the secret, so that refusal says nothing about any invitation. The receiving account is always the session's own.

## D4. Refused before spent, spent before the join, one step under a lock

A redeem takes two exclusive locks through Nextcloud's locking provider (`ClaimLock`): first the caller's own account, then the waiting account the secret opened. Under the second lock the waiting account is read again. A request that held the lock a moment ago may have spent the secret; then this one reads the empty hash and joins nothing (security review M2). The confirmation join of REQ-PIS-006 takes the same waiting-account lock.

Under the lock, everything the join would refuse is refused before the secret is spent: a waiting account of another audience (M3), and one that carries a claim the caller holds with another value (M1). A conflict answers `409 invitation_conflict` and leaves the invitation whole for its real holder.

Then the hash is emptied, and only then are the claims copied. If the copy fails, the invitation is dead and the app invites again. A secret that could work twice would be worse.

A lock that stays taken past a short wait (about a second) answers `503 try_again` and changes nothing. On an instance with file locking switched off, Nextcloud's locking provider does nothing and this protection is gone; the re-read still narrows the window.

## D5. Attempt limits that hold without a cache

`ClaimAttempts` counts wrong secrets on the caller's own account row (`claimAttempts`, `claimAttemptsSince`): five inside an hour lock the route for that account, whatever session it uses. The count is in OpenRegister, so it holds on an instance without a shared cache and across restarts. A second count per session sits in the distributed cache, and Nextcloud's own rate limit (10 a minute per address) is the outer floor. A lock answers 429, also for the right secret. The count is read and written under the caller's account lock, so wrong secrets sent in parallel each count (security review L1).

## D6. One answer for every dead secret, and honest answers about the caller

Unknown, expired, used, another organisation's, a waiting account that is no longer waiting, and one of another audience all answer `403 invitation_not_valid`.

A caller whose own account cannot receive (not active, no identity reference, another organisation than the session's) answers `403 account_cannot_receive`, before the secret is looked at and whatever the secret is. It says something about the caller's account only, like `trust_too_low`. Nothing is spent or counted, and the site keeps the secret so the visitor can sign in another way in the same tab (security review L6).

## D8. A failure never logs the secret

The secret parameter is marked `#[\SensitiveParameter]` on the controller and every service method that takes it, so a stack trace shows it redacted. The controller catches any failure below it, logs the exception class only, and answers `503 try_again` (security review L3).

## D7. Schema

This change: `portalAccount` 0.14.0 to 0.15.0, register 0.58.0 to 0.59.0. Additive: four optional properties (`claimTokenHash`, `claimExpiresAt`, `claimAttempts`, `claimAttemptsSince`).

The stacked change invitation-code-from-a-letter takes it on: 0.16.0 (register 0.60.0) adds a fifth, `claimCodeHash`; 0.17.0 (register 0.61.0) makes all five `claim*` fields readable and writable by administrators only, through OpenRegister's property authorization (security review M4).

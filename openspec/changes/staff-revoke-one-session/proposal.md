# Proposal: staff-revoke-one-session

## Why

The Sessions board ([portaliq/PtSessies](https://identity.conduction.nl/screens/board?id=portaliq/PtSessies)) gives every session row a menu with "Bekijken", "Naar het account" and "Sessie intrekken". Portaliq can revoke every session of an organisation or of one account, but not one session: a lost phone means signing the resident out everywhere. The list also has no way to the account a session belongs to.

## What Changes

- `POST /api/session-admin/revoke-session` with `jti`: revokes that one session. Instance admin and CSRF, checked by Nextcloud before the method runs, like the other session-admin routes. 404 when no live session carries the id.
- `PortalSessionService::revokeByAdmin()` records the revocation as `admin-revoke` naming the admin, so the history tells it apart from a logout.
- Two row actions on the Sessions list: "Naar het account" (opens the session's `accountRef`) and "Sessie intrekken" (asks first, live sessions only).

## Impact

- `lib/Controller/SessionAdminController.php`, `lib/Service/PortalSessionService.php`, `appinfo/routes.php`
- `src/lib/sessionActions.js`, `src/customComponents.js`, `src/manifest.json` (page PortalSessions), l10n
- No schema change.

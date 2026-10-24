---
kind: code
---

## Why

`PortalSessionService`'s constructor
(`lib/Service/PortalSessionService.php:73-84`) reads a dedicated
`jwt_signing_secret` app config value, but when it is unset or shorter than 16
characters it silently falls back to Nextcloud's **global instance secret**:

```php
$secret = (string) $config->getAppValue(Application::APP_ID, 'jwt_signing_secret', '');
if ($secret === '' || strlen($secret) < 16) {
    $secret = (string) $config->getSystemValue('secret', str_pad(Application::APP_ID, 32, '_'));
}
```

`config.php`'s `'secret'` is NC's instance-wide secret used for CSRF tokens,
`OC\Security\Crypto`, and other core signing. There is no admin-facing warning
anywhere in the app (`lib/Settings/AdminSettings.php` shows only a version
string; `SettingsController` has no health/config-check surface for this) that
tells an operator the auth edge is running on a **borrowed, higher-blast-radius
secret** rather than a dedicated one. On a fresh install with no dedicated
secret configured — the default state for every new Portaliq install per
`SettingsService`/`InitializeSettings` — every externally-issued supplier/client
bearer token is signed with the same key that also protects unrelated NC
internals. A leak of the (dev-mode-exposed, more frequently logged/rotated)
portal-facing key is lower cost to an attacker than a leak of NC's own
`config.php` secret, but they are currently the same value.

Separately, `SessionController::logout()`
(`lib/Controller/SessionController.php:139-142`) is a documented no-op:

```php
public function logout(): JSONResponse
{
    return new JSONResponse(['ok' => true]);
}
```

The docblock (lines 129-131) and `PortalSessionService`'s own class docblock
(lines 12-14) both say server-side revocation via a persisted `portalSession`
OpenRegister object is "the next slice — the schema already exists"
(`lib/Settings/portaliq_register.json` already declares `portalSession`). Task
T02 in `openspec/changes/supplier-portal/tasks.md:16` explicitly lists
"OR-backed `portalSession` persistence + revocation" as deferred and never
picked back up (no later task addresses it). Concretely: a stolen/leaked
supplier bearer today remains valid for its full 2-hour TTL
(`PortalJwtService::DEFAULT_TTL`, line 60) no matter what the subject or an
admin does — clicking "Uitloggen" in the SPA (`src/portal/App.jsx:131-139`)
only clears the browser's `localStorage` copy, it does not invalidate the
token server-side.

## What Changes

- `PortalSessionService` requires an explicit, dedicated
  `jwt_signing_secret` (>= 16 chars) to be configured before minting or
  validating **any** portal session; it SHALL NOT fall back to
  `IConfig::getSystemValue('secret', ...)`. When unset, the auth edge fails
  closed (session issuance/resolution errors, not a shared-secret guess) and
  `AdminSettings`/the settings API surfaces a clear "portal auth is not yet
  configured" warning.
- `InitializeSettings` (or a new repair step) auto-generates a random
  dedicated secret on first install via `ISecureRandom` and stores it via
  `IAppConfig`, so a fresh install is safe-by-default without an operator
  having to know to set anything — closing the gap without adding an
  onboarding step.
- Add a `portalSession` OpenRegister record per issued session (the schema
  already exists, unused): `jti`, `subjectRef`, `audience`, `organisation`,
  `issuedAt`, `expiresAt`, `revokedAt` (nullable).
- `PortalSessionService::resolveFromBearer()` additionally checks the
  `portalSession` record for the token's `jti`: a revoked or unknown-but-
  otherwise-valid-signature `jti` fails closed (treated as no session), same
  as an invalid signature.
- `SessionController::logout()` marks the current bearer's `portalSession`
  record `revokedAt = now()` instead of returning a no-op success.
- Add an admin-facing "revoke all sessions for organisation X" action (Admin
  Settings), for incident response (e.g. a supplier reports device theft).

**BREAKING**: any Portaliq instance currently running without a dedicated
`jwt_signing_secret` configured will, after this change, refuse to issue/accept
portal sessions until the (now auto-generated) dedicated secret exists — this
also invalidates every previously-issued bearer signed with the old
(system-secret-derived) key, since the signing key changes.

## Capabilities

### Modified Capabilities
- `supplier-portal`: the auth-edge requirement gains an explicit
  dedicated-secret + server-side-revocation posture; `logout()` becomes a real
  revocation, not a client-only no-op.

## Impact

- `lib/Service/PortalSessionService.php` — remove the system-secret fallback;
  read/persist sessions.
- `lib/Repair/InitializeSettings.php` (or new repair step) — generate + store
  the dedicated secret on install.
- `lib/Controller/SessionController.php` — `logout()` revokes.
- `lib/Settings/AdminSettings.php` / settings API — surface secret
  configuration state + a manual revoke-all action.
- `lib/Settings/portaliq_register.json` — `portalSession` schema already
  present; no schema change expected, only population.

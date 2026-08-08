---
example: true
capability: settings-management
status: example
built_by: openspec/changes/example-change
---

# Settings Management Specification

> ⚠️ **EXAMPLE SPEC** — This spec lives in the `portaliq` repository
> as a demonstration of the OpenSpec format. It describes the behaviour of
> `lib/Controller/SettingsController.php` + `lib/Service/SettingsService.php` in
> the template's own code. Apps built from this template should replace this
> content with their own settings REQs.

## Purpose

Defines how a template-derived app reads, writes, and reloads its application
configuration. The shape of the pattern matters more than the specific setting
keys: a thin admin-guarded controller (ADR-003) delegates to a service that
owns the OpenRegister-backed configuration. Generic client-facing error
messages plus server-side logging (ADR-005) apply throughout.

## Data Model

- **App config keys** (illustrative): a whitelist `CONFIG_KEYS` of setting
  names; reads return an empty string for unset keys.
- **OpenRegister availability**: runtime flag, surfaced to callers so the UI
  can degrade gracefully when the `openregister` app is not installed.

## Requirements

### REQ-CFG-001: Read current settings

The system MUST expose a `GET /api/settings` endpoint that returns the current
set of configuration values plus two derived fields: `openregisters` (boolean,
whether the OpenRegister app is available) and `isAdmin` (boolean, whether the
requesting user is in the Nextcloud admin group). The endpoint MUST be
accessible to any authenticated user (`#[NoAdminRequired]`).

#### Scenario: Non-admin user reads settings

- GIVEN a signed-in, non-admin user sends `GET /api/settings`
- WHEN `SettingsController::index()` invokes `SettingsService::getSettings()`
- THEN the system MUST return HTTP 200 with a JSON object containing every `CONFIG_KEYS` entry (empty string if unset)
- AND the body MUST include `openregisters` (boolean) and `isAdmin: false`
- AND unauthenticated access MUST still be rejected by the Nextcloud framework

#### Scenario: Admin user reads settings

- GIVEN a signed-in user who is a member of the Nextcloud `admin` group
- WHEN the same request is made
- THEN the response body MUST include `isAdmin: true`

### REQ-CFG-002: Update settings (admin only)

The system MUST expose a `POST /api/settings` endpoint that accepts a partial
settings payload and writes values to the app config. Only keys in `CONFIG_KEYS`
are persisted; unknown keys MUST be silently ignored. The endpoint MUST be
restricted to admin users (no `#[NoAdminRequired]`).

#### Scenario: Admin updates a known setting

- GIVEN an authenticated admin sends `POST /api/settings` with `{ "someKey": "new-value" }`
- WHEN `SettingsController::create()` invokes `SettingsService::updateSettings()`
- THEN the system MUST persist the new value to app config
- AND the response MUST be HTTP 200 with `{ "success": true, "config": <freshly-read settings> }`

#### Scenario: Non-admin tries to update

- GIVEN a signed-in non-admin user sends `POST /api/settings`
- WHEN the Nextcloud framework evaluates the controller attributes
- THEN the system MUST reject the request per the framework's admin gate (the controller itself does not need an explicit re-check)

#### Scenario: Payload contains an unknown key

- GIVEN an admin sends `POST /api/settings` with `{ "unknown": "x", "allowed": "y" }`
- WHEN `updateSettings()` iterates `CONFIG_KEYS`
- THEN the system MUST persist only `allowed`
- AND the response MUST reflect the updated state without surfacing an error for the unknown key

### REQ-CFG-003: Reload configuration from JSON file (admin only)

The system MUST expose a `POST /api/settings/load` endpoint that triggers a
fresh import of the app's bundled `*_register.json` configuration via
OpenRegister's `ConfigurationService::importFromApp()`. The endpoint MUST be
admin-only and MUST be callable at any time (not only on install).

#### Scenario: Admin triggers re-import while OpenRegister is available

- GIVEN OpenRegister is installed and enabled
- WHEN an admin sends `POST /api/settings/load`
- THEN the system MUST invoke `SettingsService::loadConfiguration(force: true)`
- AND the response MUST be HTTP 200 with the ConfigurationService result (an array including `success: true` and the configured schema/register IDs)

#### Scenario: Admin triggers re-import but OpenRegister is missing

- GIVEN OpenRegister is not installed or disabled
- WHEN `loadConfiguration()` is invoked
- THEN the system MUST emit a server-side warning via `LoggerInterface::warning()`
- AND the system MUST return `{ "success": false, "message": "OpenRegister is not installed or enabled." }`
- AND the HTTP response MUST NOT leak implementation detail beyond that generic message (per ADR-005)

### REQ-CFG-004: Graceful handling when OpenRegister is absent

The system MUST provide a stable way to detect whether the OpenRegister app is
installed so that UI and services can degrade gracefully. A missing
OpenRegister installation MUST NOT cause request handlers to throw — they MUST
log server-side and return safe fallback responses.

#### Scenario: Probe OpenRegister availability

- GIVEN any caller that needs to know whether OpenRegister is present
- WHEN `SettingsService::isOpenRegisterAvailable()` is invoked
- THEN the system MUST return a boolean derived from `IAppManager::isInstalled('openregister')`
- AND the result MUST be safe to call in any request phase (no throw, no heavy I/O)

### REQ-CFG-005: Read a per-user preference

App config (REQ-CFG-001..004) is instance-wide and admin-guarded. Per-user
preferences are a separate, user-scoped store used by the shared
`@conduction/nextcloud-vue` widgets (e.g. `CnSupportDialog` remembering that a
user dismissed a hint). The system MUST expose a
`GET /api/preferences/{key}` endpoint readable by any logged-in user, scoped to
that user alone. Keys MUST be sanitised before use so a caller cannot address
another app's or another scope's config.

@e2e exclude API-level per-user config contract with no UI surface of its own —
the endpoint is called by shared `@conduction/nextcloud-vue` widgets, never by a
page in this app, so a browser test would exercise the widget rather than this
contract; the auth and sanitisation branches are unit-testable.

#### Scenario: Logged-in user reads a preference

- GIVEN a logged-in user and a key the user has previously set
- WHEN `GET /api/preferences/{key}` is called
- THEN the system MUST return `{ "value": "<stored value>" }`
- AND an unset key MUST return `{ "value": null }` rather than an error

#### Scenario: Anonymous caller

- GIVEN no user session
- WHEN `GET /api/preferences/{key}` is called
- THEN the system MUST return HTTP 401 with a generic message (per ADR-005)

#### Scenario: Key fails sanitisation

- GIVEN a key that is empty after sanitisation
- WHEN `GET /api/preferences/{key}` is called
- THEN the system MUST return HTTP 400 and MUST NOT read any config value

### REQ-CFG-006: Write a per-user preference

The system MUST expose a `PUT /api/preferences/{key}` endpoint that stores a
value for the calling user only. Writing an empty value MUST clear the
preference rather than storing an empty string, so that a cleared preference
and a never-set preference read back identically.

@e2e exclude API-level per-user config contract with no UI surface of its own —
same reasoning as REQ-CFG-005; the clear-vs-store branch is a storage-layer
invariant observable through the API and through no page in this app.

#### Scenario: User stores a preference

- GIVEN a logged-in user
- WHEN `PUT /api/preferences/{key}` is called with a non-empty value
- THEN the system MUST persist it against that user's UID and this app only
- AND the response MUST echo `{ "value": "<stored value>" }`

#### Scenario: User clears a preference

- GIVEN a logged-in user with the preference set
- WHEN `PUT /api/preferences/{key}` is called with an empty value
- THEN the system MUST delete the stored value
- AND a subsequent read MUST return `{ "value": null }`

#### Scenario: Anonymous caller

- GIVEN no user session
- WHEN `PUT /api/preferences/{key}` is called
- THEN the system MUST return HTTP 401 and MUST NOT write any config value

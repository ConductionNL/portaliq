---
status: proposed
---

# Spec: parent-pwa-installability

**Status:** proposed
**Scope:** portaliq
**Depends on:** none

## Purpose

The portal SPA becomes installable: a web app manifest names it, a scoped
service worker caches its own shell. Requested by the learniq competitor
sweep, finding 10.3.

## ADDED Requirements

### Requirement: The portal serves a web app manifest naming the resolved portal

`GET` the manifest route SHALL return `application/manifest+json`
resolved through the SAME branding resolver `PortalPageController::
index()` uses, so `name`/`short_name` name the organisation actually being
installed and `start_url` carries the same `?org=` the page itself was
loaded with. `display` SHALL be `standalone`. The manifest SHALL be
linked from the portal's own `<head>`.

#### Scenario: The manifest names the resolved organisation
- **GIVEN** a portal loaded with `?org=gemeente-x`
- **WHEN** the manifest route is requested
- **THEN** its `name` names gemeente-x's resolved organisation, and its `start_url` carries `?org=gemeente-x`
- @e2e exclude backend resolution — covered by PHPUnit on `PortalManifestController::manifest()`; no UI surface (the browser install prompt is not driven by Playwright in the apply pass)

#### Scenario: The page links its own manifest
- **GIVEN** the portal shell page
- **WHEN** its HTML is rendered
- **THEN** a `<link rel="manifest">` element points at the manifest route
- @e2e exclude head-element assertion — covered by PHPUnit/manual review of `templates/portal.php`; no distinct browser behaviour to test beyond what the manifest scenario above already covers

### Requirement: The service worker caches the app shell and never the API

The service worker route SHALL serve `application/javascript` with a
`Service-Worker-Allowed` header scoping it to the whole portaliq app path.
The served script's fetch handler SHALL apply cache-first-with-
network-fallback ONLY to the app shell's own static assets (its JS
bundle, its CSS, the HTML entry); a request whose path falls under
`/portal/api/` (or is outside the shell asset list) SHALL always be
forwarded to the network, uncached, in EVERY code path — this is a
security requirement (an authenticated response cached and later replayed
to an unauthenticated or differently-authenticated load would leak
another subject's data), not a performance choice.

#### Scenario: A shell asset is served from cache after the first load
- **GIVEN** the service worker has cached the app shell
- **WHEN** the same shell asset is requested again
- **THEN** it is served from the cache, not the network
- @e2e exclude requires a real browser + service worker lifecycle, not exercised by the apply pass's Playwright config; asserted by code review of `serviceWorker.js`'s fetch handler and its own inline test-shaped comments

#### Scenario: An API request is never served from cache
- **GIVEN** the service worker is installed and active
- **WHEN** a request under `/portal/api/` is made
- **THEN** the service worker forwards it to the network and never reads or writes it to any cache
- @e2e exclude same reason as above; this is the one rule design.md's Risk 1 calls out as the invariant that must never regress — covered by explicit code review, not an automated browser test in this change

### Requirement: An installable browser offers a dismissible install control

When the browser fires `beforeinstallprompt`, the portal SHALL prevent
the browser's own default mini-infobar, store the event, and show its own
dismissible install control. Clicking it SHALL call the stored event's
`.prompt()`. The control SHALL disappear after `appinstalled` fires, or
after the visitor dismisses it, and SHALL never appear when the browser
never fires the event (e.g. Safari, or an already-installed app).

#### Scenario: A visitor installs the app
- **GIVEN** a browser that fires `beforeinstallprompt`
- **WHEN** the visitor clicks the shown install control
- **THEN** `.prompt()` is called on the stored event
- @e2e exclude `beforeinstallprompt` cannot be triggered by Playwright's Chromium in headless mode reliably in this apply pass; covered by manual verification and code review of the event wiring

#### Scenario: A browser that never offers installability shows no control
- **GIVEN** a browser that never fires `beforeinstallprompt`
- **WHEN** the portal loads
- **THEN** no install control is ever shown
- @e2e exclude absence-of-UI assertion, same browser-support limitation as above

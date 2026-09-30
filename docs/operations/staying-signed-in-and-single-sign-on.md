---
title: Staying signed in, the inactivity sign-out and single sign-on
sidebar_label: Inactivity and single sign-on
description: How long a portal session lasts without activity, what the resident sees before the sign-out, and how silent sign-in and the broker sign-out work
---

# Staying signed in, the inactivity sign-out and single sign-on

A resident who walks away from the portal is signed out after a set time without activity. Two minutes before that, the portal asks whether they want to stay signed in. One click keeps them signed in.

## The idle window

The app setting `session_idle_timeout` sets how long a session lasts without activity, in seconds. The default is 900 (15 minutes). The portal accepts 300 up to 3600. A value outside that range is set to the nearest limit, and a value that is not a number falls back to 900.

```bash
occ config:app:set portaliq session_idle_timeout --value=1200
```

The server enforces the window. Every sign-in token lives one window. The portal refreshes the token only after the resident presses a key, clicks or touches the screen, and only in the second half of the window. Scrolling and moving the mouse do not count. A tab left open on a shared computer therefore stops being a session.

The absolute limit `session_max_lifetime` (default 8 hours, counted from the sign-in) stays. Activity cannot stretch a session past it.

## What the resident sees

- **Two minutes before the sign-out**, a dialog says "You will be signed out in 2 minutes because you have been inactive." with "Stay signed in" and "Sign out". The focus is on "Stay signed in". A screen reader hears the remaining time once a minute.
- **Near the 8-hour limit**, the dialog says the session ends and offers "Sign in again" only.
- **After the sign-out**, the login screen says "You were signed out because you were inactive."

In the portal app, all tabs share one session: activity in one tab keeps the others signed in, and signing out in one tab signs out the others. On the public site, each tab has its own session and times out on its own.

## Silent sign-in from the organisation's other services

Another service of the organisation can link to the portal's sign-in with `silent=1`:

```
/index.php/apps/portaliq/portal/api/session/oidc/start?org=<organisation>&provider=digid&silent=1
```

The portal then asks the organisation's OIDC broker to sign the resident in without a prompt. When the broker already knows the resident, they arrive signed in. When it does not, they land on the ordinary login screen, without an error.

To try this on every visit to the portal app, set `silentSignIn` in the organisation's presentation override to a provider the organisation offers on its own OIDC broker:

```json
{ "silentSignIn": "digid" }
```

The portal tries it once per browser session, so a refused attempt never loops. Silent sign-in is not available on the integriq route.

## Signing out ends the broker session

When the organisation's OIDC broker announces an `end_session_endpoint`, signing out of the portal also sends the browser there, with `client_id` and `post_logout_redirect_uri`. The next person at a shared computer is then not signed in at the organisation's other services. The portal keeps no ID token, so the broker may ask the resident to confirm.

## What a third-party front-end must now do

A front-end on the headless API loses its session after one idle window unless it calls `POST /portal/api/session/refresh`. `GET /portal/api/session` and the refresh answer both return `expiresAt`, `hardExpiresAt` and `idleTimeout` (unix seconds and seconds), so the front-end can schedule its refresh and its own warning. `DELETE /portal/api/session` may answer a `logoutUrl` to send the browser to.

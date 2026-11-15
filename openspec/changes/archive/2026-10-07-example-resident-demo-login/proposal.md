---
kind: code
---

## Why

At the Zuiddrecht demo (6 October) "Inloggen als voorbeeldinwoner" sent the visitor to Nextcloud's
own login form: the example resident's way in is the `nextcloud` mode, which exchanges a Nextcloud
session for a portal session. A demo visitor expects one click, and a password prompt for a
made-up resident reads as a broken site.

The test sign-in (`dev-login`) is not the answer: open, it mints a session for ANY subject
reference anyone names, and `debug` mode opens it too.

## What Changes

- **One click on a demo.** `GET /portal/api/session/example-resident?id=<resident>&portal=<slug>`
  mints a portal session for the example resident's own Nextcloud account id, as the `nextcloud`
  mode would after the form, and hands the bearer back in the URL fragment. It is closed unless an
  administrator sets `occ config:app:set portaliq example_resident_demo_login --value=yes`
  (`debug` mode does NOT open it), the example resident is installed (app config
  `example_resident_<id>` with a user id), the named portal is the resident's own and offers the
  `nextcloud` mode, and the resident's portal account is active. The subject is the record's, never
  the caller's. Rate limited and throttled like `dev-login`.
- **The site offers it as the one-click card.** The shell's sign-in config carries
  `exampleResident` (the id) while the switch is on; the Voorbeeldinwoner card then links to the
  one-click route and shows "Alleen op deze demo" under its button. With the switch off the card
  keeps the Nextcloud-account path.
- **Signing out** ends the portal session as before (`DELETE /portal/api/session`); no Nextcloud
  session is created by the one-click path, so nothing else is left behind.

## Capabilities

### Modified Capabilities
- `example-resident`: the one-click demo sign-in and its guard.

## Impact
- `lib/Controller/SessionController.php`, `appinfo/routes.php`,
  `lib/Service/PortalRuntimeConfigResolver.php`, `lib/Controller/PortalPageController.php`,
  `src/site/lib/authApi.js`, `src/site/App.vue`, `src/site/components/chrome/SignInPage.vue`,
  `src/site/components/AccountArea.vue`, docs.

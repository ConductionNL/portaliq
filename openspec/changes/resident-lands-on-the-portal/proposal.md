---
kind: code
---

# Proposal: resident-lands-on-the-portal

## Why

The live test of the Zuiddrecht demo on 13 and 14 October 2026: a resident who finished the Nextcloud registration app's last step landed on `/apps/launchpad/`, the workplace, not on Mijn Zuiddrecht. The registration app signs the new user in and redirects to `IURLGenerator::linkToDefaultPageUrl()`. Portaliq's way back (REQ-NCL-006) only works once the resident opens portaliq's app page, and on the tenant the system `defaultapp` is `launchpad`.

`linkToDefaultPageUrl()` (Nextcloud 32 to 35, `URLGenerator` and `NavigationManager::getDefaultEntryIdForUser()`) answers, in this order:

1. `$_REQUEST['redirect_url']`. The registration app's last request is the POST of its user form, reached through the activation mail. A `redirect_url` on the first link does not travel through the mail and the three forms, so this is no reliable lever.
2. The app config `core`/`defaultpage`. Instance-wide, so it would send staff to the portal too.
3. The user's own preference `core`/`defaultapp`, before the system `defaultapp`. Per user, and it is the start page the user would set themselves under personal settings.

## What changes

- **A member of a portal's resident group starts on portaliq** (REQ-NCL-007). A listener on `OCP\Group\Events\UserAddedEvent` sets the user's `core`/`defaultapp` to `portaliq` when a published portal that offers the `nextcloud` way in names the group as `authentication.registration.residentGroup`. The registration app adds the user to its group inside `createAccount()`, before its redirect, so the redirect already reads the new preference. Portaliq's app page then sends the resident on to their portal (REQ-NCL-006).
- A preference the user already has is left alone, an administrator is never touched, and a failure is logged and never stops the membership.
- On `UserRemovedEvent` the preference is taken back when it is still `portaliq` and the user is no resident of any portal any more.

## Decisions taken

- **The group event, not a first-login event.** The membership is the statement that the user is a resident, it happens once, and it happens before the registration app's redirect. A login listener would read every portal on every sign-in of every user.
- **No change to `DashboardController`.** The way back it already has is the second half of this route.

## Known limits

- A user who was in the group before a portal named it does not get the preference. The Zuiddrecht example set adds its residents before its change names the group, so the example residents are not affected; they sign in through the site. An administrator can set it with `occ user:setting <uid> core defaultapp portaliq`.
- A tenant that sets `core`/`defaultpage` overrides every user's preference, as Nextcloud designs it.

## Screens

None. This is the route between the registration app's form (`screens-src/zuiddrecht/AccountAanmaken.dc.html`) and Mijn Zuiddrecht.

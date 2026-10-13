---
kind: code
---

# Proposal: nextcloud-account-registration

## Why

A visitor of a portal that signs residents in with a Nextcloud account (mode `nextcloud`) cannot make that account. The sign-in page offers "Inloggen met uw account" and "Wachtwoord vergeten", but no "Account aanmaken", so only people an administrator created can sign in. Ruben decided on 13 Oct 2026 for the Zuiddrecht demo: no Keycloak, residents are plain Nextcloud users in their own group, and the account is activated by mail.

Nextcloud already has an app for exactly that: `registration` (github.com/nextcloud/registration, 3.0.0 for Nextcloud 32 to 34). It sends the activation mail, asks for a password, puts the new user in one configured group (`registered_user_group`) and signs them in. Two things are missing in portaliq:

1. **The way there.** "Create an account" exists today only as portaliq's own e-mail form, opened by an OIDC provider of kind `generic` plus `authentication.registration.policy` (`PortalWaysInResolver`). A `nextcloud` portal has no door at all.
2. **The way in.** `SessionController::nextcloud()` refuses every Nextcloud user without a `portalAccount` (REQ-NCL-003) and creates none. A freshly registered resident is answered 403 `no_portal_account`.

And one thing in between: after activation the registration app signs the user in and sends them to Nextcloud's default page, which is the workplace, not the portal.

## What changes

- **A portal declares its residents' group**: `authentication.registration.residentGroup` (and optionally `residentAudience`, default `citizen`). Only a portal that offers `nextcloud` can declare it; for every other mode the key means nothing.
- **"Account aanmaken" on the account route's card** (REQ-NCL-005), leading to the registration app's own form. Served by `ContentController::site()` as `registerUrl`, next to `lostPasswordUrl`, and only when the registration app is enabled and puts new users in exactly the declared group. The address is built from a route name; nothing comes from the request.
- **A member of the resident group gets their portal account on first sign-in** (REQ-NCL-003, modified): active, subjectRef the user id, the portal's organisation and resident audience, trust still `low`. Written by the existing `NextcloudAccountProvisioner`, so its conflict rules hold. Everyone outside the group is still refused.
- **The way back** (REQ-NCL-006): a resident who opens portaliq's app page (where the tenant points Nextcloud's default page for users without the dashboard) is redirected to their portal's account route, which signs them in to the portal.

## Rows covered

- Zuiddrecht scenario A: create an account, activate it by mail, sign in to Mijn Zuiddrecht. Screen: board `screens-src/zuiddrecht/AccountAanmaken.dc.html` and the "Account aanmaken" link on the Inloggen board.

## Decisions taken

- **A link, not a form of ours.** Portaliq keeps no passwords (`SessionController::nextcloud()`: "no password field of ours to attack"). The registration app owns the form, the mail, the throttling (`AnonRateLimit` 5 per 5 minutes on each step) and the domain list. Portaliq's own registration form for OIDC portals is untouched.
- **Membership is the test, not "has a Nextcloud account".** Auto-creating for every Nextcloud user would make every staff member a citizen of every portal with the mode. The group is the portal's own statement of who its residents are, and the registration app is the only thing that fills it.
- **The door shows only when it leads somewhere** (REQ-IWI-005): a registration app that is off, or fills another group, would make accounts the portal never admits.
- **REQ-IWI-014 stands.** The e-mail link never opens registration: the door depends on the `nextcloud` mode only, and `waysIn.register` is untouched.

## Out of scope

- The board's text ("staff review first") changes to activation by mail; that is a board edit.
- Which apps, sharing and quota a resident has on the workplace: tenant configuration with `occ`, listed in the PR.
- Higher trust for a registered account. It stays `low`, below DigiD.

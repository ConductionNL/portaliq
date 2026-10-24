# Design: nextcloud-account-registration

## Screen

The Zuiddrecht board **Inloggen** lists "Account aanmaken" under the account sign-in, beside "Wachtwoord vergeten". This change draws it on the account route's card in `chrome/SignInPage.vue` and in the plain list in `AccountArea.vue`, in the same link style as "Wachtwoord vergeten". The board **AccountAanmaken** (`screens-src/zuiddrecht/AccountAanmaken.dc.html`) draws the e-mail form; that form is the registration app's, themed by Nextcloud's theming, not a portaliq page.

## The declaration

```json
"authentication": {
  "modes": ["nextcloud"],
  "registration": { "residentGroup": "inwoners", "residentAudience": "citizen" }
}
```

`residentAudience` is optional and defaults to `citizen`. `registration.policy` keeps its meaning for portaliq's own form and plays no part here.

## One service

`lib/Service/Identity/NextcloudRegistrationDoor.php` answers:

- `residentGroup(portal)`: the declared group, `''` unless the portal offers `nextcloud`.
- `registerUrl(portal)`: `IURLGenerator::linkToRouteAbsolute('registration.register.showEmailForm')`, `''` unless the group is declared, `IAppManager::isEnabledForAnyone('registration')`, and `IAppConfig` `registration/registered_user_group` equals the group (`none` means no group). A value stored under another type shuts the door.
- `isResident(portal, uid)`: `IGroupManager::isInGroup(uid, group)`.
- `residentPortalOf(uid)`: the first published portal whose group holds the user; never for an administrator.

## Callers

- `ContentController::site()` serves `registerUrl`; `signInRoutes()` copies it onto the `nextcloud` route (not on the demo, not on any other mode); the two sign-in views draw it.
- `SessionController::nextcloud()`: when `findBySubjectRef(uid)` is null and the user is a resident, it calls `NextcloudAccountProvisioner::provision()` (app id `portaliq`, the portal's slug, organisation and resident audience, the user's address unverified and display name) and reads the account back. A refusal (`conflict`, `portal_mismatch`, ...) leaves the 403.
- `DashboardController::page()` and `catchAll()`: for a resident, a 302 to `portaliq.session.nextcloud` with the portal's slug. No `returnTo`, so the account route lands on the portal's own site.

## The round trip

1. Portal sign-in page: "Account aanmaken" opens `/apps/registration/` on the host the site API answered on.
2. The registration app mails a link, the visitor sets a password, the app puts them in `residentGroup`, signs them in, and redirects to `linkToDefaultPageUrl()`.
3. The tenant sets `defaultapp` to `dashboard,portaliq,files` and limits `dashboard` to the staff group. Staff land on the dashboard, a resident (no dashboard) on portaliq, which sends them to the account route.
4. The account route writes the resident's portal account and lands on the portal signed in.

# Proposal: identity-profile-page

## Why

A resident cannot see or change anything about their own portal account.
The backend for it shipped with `portal-identity-and-the-organisations-cases`
(open, 15 of 15 tasks checked): `PATCH /portal/api/identity/details`,
`POST /portal/api/identity/email/confirm` and `POST /portal/api/identity/remove`
are routed (`appinfo/routes.php:278-280`). No screen in `src/portal`,
`src/site` or `src/manifest.json` calls any of them (grep at `eeda3fa`). And the
confirmation secret that `PortalSelfServiceService::updateDetails()` mints is
never mailed: `confirmationToken` is read only by the controller, which drops
it (`lib/Controller/PortalAccountSelfController.php:110`). So a changed
address can never be confirmed.

Five rows in the portaliq parity matrix (`openspec/parity/capabilities.json`,
compared 2026-09-26) name the gap. All five are in portaliq's core area
(identity), which is why the OpenSpec pass of 2026-09-27 decided `build`.

**`id-update-own-details`**, "Change your own name or e-mail address from the
portal, with the new address confirmed before it is used." Portaliq `no`,
built.state `built`: "tasks.md T10 [...] is marked done for the backend only."
Open Inwoner Platform is `yes`: "src/open_inwoner/accounts/views/profile.py:237
EditProfileView (name, e-mail, phone, synced to OpenKlant/e-Suite :462);
src/open_inwoner/accounts/middleware.py:20 EmailVerificationMiddleware forces
verification of a changed address".

**`dem-cl-multiple-contact-addresses`**, "Keep several e-mail addresses and
phone numbers on your profile and mark one of each as preferred." Portaliq
`no`, `none`: "One e-mail per account, no phone numbers, no preferred marker".
Demand: changelog, https://github.com/maykinmedia/open-inwoner/blob/v2.4.3/CHANGELOG.rst#L149.
Open Inwoner Platform is `yes`: "src/open_inwoner/accounts/models.py:68
DigitalAddress, :189 preferred_address; src/open_inwoner/accounts/views/profile.py:414
formset save, synced with OpenKlant".

**`cmp-id-contact-channel`**, "Choose how the organisation contacts you:
portal, e-mail, phone or post." Portaliq `no`, `none`: "portalAccount carries a
single e-mail address, no channel preference of any kind." xxllnc Zaken PIP is
`yes`: "backend/perl-api/lib/Zaaksysteem/Controller/Plugins/PIP.pm:356
preferred_contact_channel".

**`cmp-id-prompt-missing-email`**, "Be prompted to add an e-mail address when
the organisation has none on file." Portaliq `no`, `none`:
"portalAccount.needsAlternativeContact is set by NotificationDispatchJob but
nothing in src/ reads it". Open Inwoner Platform is `yes`:
"src/open_inwoner/accounts/middleware.py:8 NecessaryFieldsMiddleware redirects
to [...] NecessaryFieldsUserView when required fields such as e-mail are
missing [reached on after first DigiD/eHerkenning login]".

**`id-remove-own-account`**, "Ask for your own portal account to be removed,
while your cases stay." Portaliq `no`, `built`: "tasks.md T11 marked done; no
UI affordance in the portal SPA to request it." Open Inwoner Platform is `yes`:
"src/open_inwoner/accounts/views/profile.py:208 MyProfileView.post deletes the
user and flushes the session; [...] text: personal data and cases are not
deleted".

## What changes

- A "My account" page in the signed-in portal with four parts: your name and
  e-mail addresses, your phone numbers, how the organisation contacts you, and
  removing your account.
- The confirmation mail is actually sent. A changed or added address is used
  only after you follow the link in it.
- Several e-mail addresses and phone numbers per account, one of each marked
  preferred. The preferred, confirmed e-mail stays in `portalAccount.email`,
  which is the only address `NotificationDispatchJob` reads, so dispatch does
  not change.
- A contact channel choice: portal only, e-mail, phone or post. Portaliq
  records it and announces the change as an event, so a case app can respect
  it when it writes a letter or phones.
- A prompt after sign-in when the account has no confirmed e-mail address, or
  when dispatch flagged `needsAlternativeContact`.
- A read endpoint for the page: `GET /portal/api/identity/details`, your own
  account only.

## Rows this closes

| matrix | row | name | own rating | what is missing |
| --- | --- | --- | --- | --- |
| portaliq | `id-update-own-details` | Change your own name or e-mail address, confirmed before use | no | the screen and the confirmation mail |
| portaliq | `dem-cl-multiple-contact-addresses` | Several e-mail addresses and phone numbers, one of each preferred | no | the model and the screen |
| portaliq | `cmp-id-contact-channel` | Choose how the organisation contacts you | no | the choice and its event |
| portaliq | `cmp-id-prompt-missing-email` | Be prompted to add an e-mail address | no | the prompt |
| portaliq | `id-remove-own-account` | Ask for your own portal account to be removed, while your cases stay | no | the screen |

## Existing work it builds on

- `portal-identity-and-the-organisations-cases` shipped the backend of
  T10 (change your details) and T11 (remove your account). This change adds the
  screen, the mail and the multi-address model. It does not rewrite
  `PortalSelfServiceService::removeAccount()` (`lib/Service/Identity/PortalSelfServiceService.php:263`),
  which already blanks the account and keeps the cases.
- `notification-preferences-per-role` shipped the e-mail opt-out on the same
  PATCH route. Its screen is `inbox-notifications-and-preferences`, not this
  change.

## Sibling halves

- A case app that sends letters or phones (dossiq) listens for
  `PortalContactDetailsChangedEvent` to honour the channel choice. That listener
  is the case app's to write.

## Out of scope

- Verifying a phone number. There is no SMS channel in portaliq; a phone
  number is stored for the organisation to call.
- Syncing addresses to a customer register (Open Inwoner syncs to OpenKlant).
  A case app that keeps its own contact record reads the event.

---
kind: code
---

# Proposal: password-reset-from-the-sign-in-page

## Why

A resident who signs in with an account and a password forgets the password. The portal's sign-in page has no "Wachtwoord vergeten", so she phones the municipality.

Open Inwoner resets a password from its own login page, with IP throttling (`src/open_inwoner/accounts/views/password_reset.py:6`). Portaliq keeps no passwords on purpose: the account route hands the visitor to Nextcloud's login form (`lib/Controller/SessionController.php:841`-`850`), and Nextcloud resets the password with its own lost-password flow. What is missing is the way there. The Zuiddrecht board **Inloggen** draws "Wachtwoord vergeten" under the sign-in button.

## What changes

- **A "Wachtwoord vergeten" link** on the portal's sign-in page, shown only when the portal offers the account route (`nextcloud` in `portal.authentication`).
- **It opens Nextcloud's own reset.** The link points to Nextcloud's lost-password page with a return address back to the portal's sign-in page. Portaliq stores, receives and checks no password, and adds no reset endpoint.
- **The way back.** After the reset Nextcloud's login form returns the visitor to the portal through the existing account route.

## Rows covered

- `id-password-reset` (decision 101), screen Inloggen.

## Decision taken

Portaliq does not start keeping passwords to match Open Inwoner. A reset that portaliq owned would need its own password store, throttling and mail flow, and the account route deliberately has "no password field of ours to attack". If Ruben wants e-mail and password accounts owned by the portal, that is a new change.

## Out of scope

- Password accounts held by portaliq.
- A second factor for password accounts (`cmp-sig-2fa-password`, decided-no).

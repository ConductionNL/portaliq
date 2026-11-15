---
kind: code
depends_on: [portal-shared-runtime, site-reaches-portal-parity]
---

# Proposal: site-resident-menu

## Why

On the Vue site a signed-in resident's blue bar held the website's pages and
every item of their own area: about twenty links in one row, some of them
twice. Ruben (2 Oct): the website's navigation stays in the blue bar, and the
resident's own items get a menu on the left, as on the Tilburg Woo site.

Two names showed twice, from two causes:

- "Berichten": the Dutch bundle translated both the shell's inbox and its
  conversations (message threads) as "Berichten". That is portaliq's own
  string, now "Gesprekken" for the threads.
- "Mijn zaken" and "Berichten": dossiq contributes pages with the same names
  as the shell's own "Mijn zaken" (cases from every app) and inbox. Those are
  two sources. The menu now names the app beside such an item.

## What changes

- The blue bar carries the CMS header menu only, signed in or not.
- The header's right side, signed in, shows the resident's name, a link
  "Mijn omgeving" to `/mijn`, and "Uitloggen". Signed out it keeps the
  sign-in links.
- Every `/mijn` page, signed in, shows the resident's own menu beside the
  content: cases and tasks, one group per contributing app, messages and
  news, then details and account. Public pages show no menu and keep their
  full width.
- On a phone the menu folds behind one button.

## Not in scope

- The React portal (`src/portal`) is frozen and is being retired.
- Icons per item: contributed icon names mix MDI names and Nextcloud icon
  classes, and mapping them is a bundle budget decision.
- A manifest field for a page's group. Groups follow the contributing app.

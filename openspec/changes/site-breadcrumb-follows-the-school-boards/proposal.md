## Why

Proof run 3 (9 October) put each school portal's public pages beside their boards. The breadcrumb and
the sign-in page differ in the same way on all four:

- "Nieuws en documenten" (`/zoeken`) reads "Home › Nieuws": the trail takes the header menu's word
  for a route the menu links to. That rule is right for Zuiddrecht (board Kop, "Home › Afval"), but
  every school board names the page on screen by its own title.
- The sign-in page reads "Home › Mijn Wilgenboom" (the own area's name); every board reads
  "Home › Inloggen".
- De Wilgenboom has one way in. Its board draws that card as one row: the DigiD mark, the title with
  "Met de DigiD-app of met een sms-code" under it, the button below. The designed sign-in style puts
  the line under the mark for every card, which is what the boards with two cards draw.

## What Changes

- Register 0.71.0, portal schema 0.14.0: `breadcrumb`, `menu` (default) or `page`. Additive.
- `PortalShell::breadcrumb()` projects it on the public site contract.
- `src/site/lib/crumbWords.js`: `crumbLabel()` (the last crumb takes the page title when the portal
  chooses `page`) and `signInCrumbs()`; `App.vue` uses both: the own area shown to a visitor who is
  not signed in is the sign-in page, and its crumb reads "Inloggen".
- `css/site-theme.css`: a lone sign-in card keeps its line beside the mark, under the title.
- Tests: `tests/site-look/breadcrumb-words.spec.mjs`, `PortalShellTest`, `PortaliqRegisterConfigTest`.

## What learniq declares (lane L3)

- `portal.breadcrumb: "page"` in `lib/Settings/portals/{po,vo,mbo,training}.json`, and `breadcrumb`
  added to `ExamplePortalProvisioner::FILLABLE`, so a fresh and a repeated load write it.

## Depends on

- `site-article-page-follows-the-board` (PR #1422): this branch is stacked on it (both edit the
  breadcrumb in `App.vue`).

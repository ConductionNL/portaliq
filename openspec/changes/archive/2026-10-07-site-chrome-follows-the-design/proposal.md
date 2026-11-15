## Why

Four school portals (De Wilgenboom, Vaartveld College, Esdoornveen, Warmtepompacademie) and the
Zuiddrecht municipality have finished designs for the website's header, footer and sign-in page.
The site could not draw them: no motif under the header or over the footer, no search box in the
header, a sign-in link per mode instead of one button to the own area, a bare signed-in name, no
button or contact column in the footer, the light logo missing on the dark footer, and a sign-in
page that is one heading and a row of buttons. A portal switched to a school theme looked worse
than on the old `example-*` set (closed in thematiq `brand-motif-on-portals`, which this change
reads).

Gaps G-01, G-02, G-03, G-04, G-07, G-08 and G-18 of the school portal plan.

## What Changes

- **The motif** under the header and over the footer, as CSS on existing elements
  (`css/site-theme.css`), from the theme's `--cn-brand-stripe-*` tokens. Nothing is drawn for a set
  without a stripe. Under the header a set without a motif image draws one line in its first
  stripe colour (Zuiddrecht's red line); over the footer the inverse image, else the three bands.
- **The designed header**, for a portal that declares `headerSearch.enabled` or `accountLabel`: a
  search box that opens the portal's search page, one button to the own area while signed out, a
  person chip (initials, name, the organisation from `session.organisationName`) and a sign-out
  link while signed in, and on a phone a menu button that opens the menu bar and the search box.
  Loaded on demand (`components/chrome/HeaderTools.vue`). Other portals keep their header markup.
- **The current menu item** is bold with a 4px bar in the theme's accent (the primary for a set
  without one).
- **The designed footer**, for a portal whose footer declares `cta` or `contact`: brand column
  first (light logo, text, outlined button), then the contact column, then the menu columns; the
  bottom band one step darker than the footer. Other footers keep their order.
- **Logo variants**: `site.php` names the set's light logo (`img/logos/<set>-dark.svg`) and emblem
  (`-emblem.svg`) as absolute addresses (`--nldesign-logo-inverse-url`, `--nldesign-emblem-url`).
- **The hero** takes a light ground, dark type, a filled first button and the emblem as a faint
  watermark when the theme says so.
- **The sign-in page as role cards** (`components/chrome/SignInPage.vue`, on demand) for a portal
  that writes `authentication.modeLabels` or `authentication.signInPage`: a card per way in (who it
  is for, what it opens, the button, a hint), a notice, a line for staff, and a side panel with the
  motif along its top.
- **Portal schema and content API**: `headerSearch`, `accountLabel`, `footer.cta`,
  `footer.contact`, `authentication.modeLabels`, `authentication.signInPage`, projected on named
  keys by `PortalShell` (links only when they can be followed).
- **For lane L2**: `gridContext()` hands the portal's sign-in routes to the widget grid
  (`signInRoutes`), for the `nlSignIn` block.

## Impact

- Additive and opt-in per portal: a portal that declares none of the new keys renders as before,
  except that a set with a stripe now draws it on the site and the current menu item gets its bar.
- Bundle: the new header tools and the sign-in page are lazy chunks; the entry gains the opt-in
  wiring only. The production entry size is measured by CI (a local production build is not run
  on this machine).
- Deviations, accepted in the plan: the slanted cut of Esdoornveen aligns to the viewport edge,
  not the content column (D-1); the participant's e-mail link of the Warmtepompacademie is shown
  as the account way in until a magic link exists (D-6).

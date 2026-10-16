## Why

The fresh install of the four school portals (proof run 1, 06 Oct, "Visual") showed three layout
defects that are portaliq's, re-measured on `development` c26e822:

- a content page whose body opens with an `nlHeading` at level 1 shows its title twice: the
  renderer's own `page-title` h1, then the block's h1 ("Uw kind afwezig melden");
- the main menu marks only an item whose link is exactly the route, so on
  "/praktisch/afwezig-melden" no item is marked, where the board marks "Praktisch";
- the designed footer has a fixed brand column plus three, so a fourth link column
  ("Over deze website") drops under the logo on a desktop.

## What Changes

- `src/site/lib/pageHeading.js` `blocksOwnHeading()`: a `hero`, a `publicationDetail` or an
  `nlHeading` at level 1 owns the page's h1; `App.vue` `bodyProvidesHeading()` asks it.
- `src/site/lib/menuCurrent.js` `menuCurrent()`: `page` for the item that is the route, `true`
  for the item whose link is a section of the route (not for `/`). `SiteMenu.vue` sets it as
  `aria-current` on top-level items; `css/site-theme.css` gives `aria-current="true"` the same
  bold label and bar as the page item, also in the in-line variant.
- `css/site-theme.css`: the designed footer's brand column, then one column per contact block or
  menu on one row (`grid-auto-flow: column`) above 1024px; rows again below.
- `tests/site-look/page-layout.spec.mjs`.

## Not in this change

- The right-hand column of the school home pages (sign-in card, then "Deze maand op school")
  falls under the news because learniq's portal file places the event list at `gridX 0`,
  `gridWidth 12`. With `gridX 8`, `gridWidth 4` and the news spanning both rows it stands beside
  the news (lane L3). Same for the content page's "Meer over praktische zaken", which starts at the
  row of the markdown instead of the top.
- The footer shows "Contact" twice because learniq declares `footer.contact` and a menu "Contact"
  (proof item 7, lane L3).

## Impact

- `App.vue` (one call), `SiteMenu.vue`, two small helpers, `css/site-theme.css`, one test.
- Zuiddrecht: its footer has three menu columns, so the row stays as it was; its section items get
  the bar on deeper pages too.

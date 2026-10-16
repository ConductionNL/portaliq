## Why

The proof run of 9 October (run 3) put the live news article of each school portal beside its board
Artikel. Four differences are portaliq's, the same on all four portals:

- the page prints its own title "Nieuws" as an h1 above the article's own h1;
- the breadcrumb reads "Home › Nieuws › Nieuws": the id segment takes the page's title, where the
  board ends on the article's title and runs through "Nieuws en documenten";
- no menu item is marked, where the board marks "Nieuws" (the menu item links to `/zoeken`, the
  article lives at `/nieuws/<id>`);
- the facts under a heading ("**Wanneer:** ...", "**Waar:** ...") show as a bold bullet list, where
  the board draws a grey block of labels and values.

The same run found that Enter in the catalogue's search field searches for "[object Event]"; lane
FIX-A fixes that in `WidgetGrid.vue` (`portal-subject-rate-limit`), so this change does not.

## What Changes

- `src/site/lib/pageHeading.js`: an `nlNewsArticle` owns the page's h1.
- `nlNewsArticle` gains `sectionHref` and `sectionLabel`. Once the item is read it emits `subject`
  `{title, section}`; `WidgetGrid` forwards it; `App.vue` keeps it per route and builds the trail
  with `src/site/lib/subjectTrail.js` (`subjectCrumbs`), marks the menu with `menuRouteOf`, and
  names the browser tab after the article.
- `src/site/widgets/nlNewsArticle/article.js` `articleParts()`: a list whose every item opens with a
  bold label is a set of facts; the article renders it as a `<dl>` on the muted surface
  (`--thematiq-surface-color`, then `--nldesign-color-background-hover`).
- `tests/site-look/article-page.spec.mjs`.

## What learniq declares (lane L3)

Per portal file (`lib/Settings/portals/{po,vo,mbo,training}.json`), on the `/nieuws` page:

- `nlNewsArticle.props.sectionHref: "/zoeken"` and `sectionLabel: "Nieuws en documenten"` (mbo:
  `"Nieuws"`, its search page title), and `kindLabel: "Nieuws"` for the pill above the title;
- the right column: `nlNewsList` and the `nlSignIn` card both at `gridX 8`, `gridWidth 4`, the card
  first (`gridY 0`), with `tone: "light"` (the board's light aside card "Nieuws uit de groep van uw
  kind" / "Kom je ook?") instead of a full-width dark card at `gridY 8`.

## Not in this change

- The photo placeholder and a closing paragraph are story data (lane L3).
- The breadcrumb's words for a page that is a menu item ("Nieuws" for `/zoeken`, where the board
  reads the page title) are a portal choice: change `site-breadcrumb-follows-the-school-boards`.

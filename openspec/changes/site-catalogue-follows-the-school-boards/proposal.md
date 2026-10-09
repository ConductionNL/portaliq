## Why

Proof run 3 (9 October) put the search page of each school portal beside its board ("Nieuws en
documenten" on De Wilgenboom and Vaartveld, "Opleidingen" on Esdoornveen, "Cursusaanbod" on the
Warmtepompacademie). The catalogue block draws what the server sends, but:

- the school news has no facets, so there is no filter column: the boards filter by kind ("Soort":
  Nieuws, Nieuwsbrieven, ...) and by audience ("Voor wie"), and choose one value from radios or a
  menu ("Voor wie", "Schooljaar", "Leerjaar");
- the field has a visible label above it, where every board shows only the field and its button;
  the Cursusaanbod board puts the field in the filter column with a magnifier button;
- a result card has no chevron; the Opleidingen and Cursusaanbod boards draw no kind label above the
  title but the first fact as a label under the summary ("Niveau 4 · BOL of BBL · 4 jaar"); a dated
  card repeats its first fact above the title;
- the pages are plain buttons, the sort menu and checkboxes unstyled.

## What Changes

- `PublicCatalogueQuery::run()`: `kindFacet` (with `kindNews`, the word for a news item) and
  `audienceFacet` add a facet by kind and by a news item's audience; a label an item declares stays.
  `PublicCatalogue` keeps a news item's audience; the endpoint takes them as `facetsBy` JSON.
- `nlCatalogue`: props `kindFacet`, `audienceFacet`, `facetDisplay` (label to `checkbox`, `radio` or
  `select`), `labelHidden`, `searchPlacement` (`top`, `rail`), `cardStyle` (`kind`, `meta`); a chevron
  on a linked card; no repeated fact above a dated card's title; styled field, button, menus,
  checkboxes, "Filters wissen" and pages, from theme tokens.
- Tests: `tests/site-look/catalogue-boards.spec.mjs`, `PublicCatalogueTest`.

## What learniq declares (lane L3)

On each portal's search page block (`nlCatalogue` props in `lib/Settings/portals/*.json`):

- po and vo `/zoeken`: `kindFacet: "Soort"`, `audienceFacet: "Voor wie"`, `labelHidden: true`,
  `facetDisplay: {"Voor wie": "radio"}`; the vo board's "Afdeling", "Leerjaar" (`select`) and the po
  board's "Schooljaar" (`select`) need facets on the items, which news does not carry (story data and
  a news facet field: not in this change).
- mbo `/opleidingen`: `cardStyle: "meta"`, `labelHidden: true`, `sort: "relevance"`; the items'
  `href` (cards are no links today), `meta: ["Niveau 4", "BOL of BBL", "4 jaar", "crebo 25743"]` and
  a "Richting" facet come from the public index.
- training `/cursusaanbod`: `cardStyle: "meta"`, `searchPlacement: "rail"`; the level as the first
  meta part ("Basis", "Certificaat"), `noteTone: "warning"` on "Nog N plekken", and `badge` with the
  price.

## Not in this change

- A date range facet ("Periode", Vaartveld), facet help text (Esdoornveen "Leerweg"), a per-kind pill
  colour (Nieuwsbrief in yellow), the line "Cursus 1 tot 5 van 9".
- The number of results: the boards count newsletters, documents and events that are not seeded.

# Proposal: portal-public-catalogue

## Why

The school boards (plan `PORTAL-PLAN.md` item W2-3, gaps G-12, G-14, G-15) let a visitor who is not signed in search what a school offers: Vaartveld's "Nieuws en documenten" (search, facets, results, pages), Esdoornveen's "Opleidingen", the Warmtepompacademie's "Cursusaanbod" (date tile, place, start month, places left), and the editor's agenda and course-day blocks filled from data. Today a CMS page can only show authored items (`nlEventList` takes typed-in dates) and the portal's public news.

`portal-public-search` (0/10) planned this as a search over OpenRegister with anonymous RBAC, and is blocked on `rbac-default-authenticated` in OpenRegister: opening anonymous object search while unmarked schemas are readable would expose them. This change takes the other road the plan names, an **anonymous provider index**: the app decides, in code, which of its things are public and hands portaliq a list; portaliq reads no app object itself. It does not replace `portal-public-search`, which stays the route for CMS pages and files once OpenRegister is ready.

## What changes

- **Optional provider method `getPublicIndex(string $portal): array`.** An app answers, for one portal, items `{id, type, kind, title, summary?, date?, endDate?, dateLabel?, meta?[], facets?{label: value(s)}, note?, noteTone?, href?, badge?}`. `PublicIndexItems` holds every answer to that shape: markup stripped, lengths capped, a date that does not parse dropped, `href` only a site path or http(s), unknown keys dropped, ids prefixed with the app. `getPublicIndex` joins the reserved names a manifest can never point a timeline, steps or contacts provider at.
- **`PublicCatalogue`** gathers a portal's public news (`PublicNewsReader::allFor`, at most 200) and every installed app's index, cached five minutes per portal; **`PublicCatalogueQuery`** searches (every word, case and accents ignored, over title, kind, summary and meta), filters by type and facet (any value within a facet, all facets together; each facet counted as if its own choice were not made), keeps only what is still to come when asked, sorts (best match, earliest date, newest, name) and pages (at most 50).
- **`GET /api/content/catalogue`** (`ContentCatalogueController`): `portal`, `search`, `types`, `filters` (JSON), `sort`, `page`, `limit`, `upcoming`; gated like `/api/content/news` by the portal's sign-in modes; cacheable for a visitor without a bearer.
- **Widget `nlCatalogue`** (on demand): heading, intro, search field (it starts with the header search's `_search` term), facets with counts and "Filters wissen", a sort, result cards (kind pill, date, meta, title link, summary, note) or a date tile per result (`display: dated`), pages. The server filters; the widget draws only what it is sent. "Zoeken lukt nu niet" is never "Niets gevonden".
- **`nlEventList` `source: {types: [...]}`**: the dated list fills itself with the catalogue's upcoming dated items of those types, instead of authored items, so the agenda and course-day blocks stay current.

## Not in this change

- CMS pages, documents and file text in the results (`portal-public-search`, after OpenRegister's `rbac-default-authenticated`).
- A detail page for a course or a programme: an item links where the app says, or nowhere.
- Prices: the boards show "[PRIJS]" (D-11); an app may send it as `badge`.

## Impact

- `lib/Contribution/PublicIndexItems.php`, `lib/Service/PublicCatalogue.php`, `lib/Service/PublicCatalogueQuery.php`, `lib/Controller/ContentCatalogueController.php` (new); `PublicNewsReader::allFor`; `TimelineProviderMethod::RESERVED`; `appinfo/routes.php`.
- `src/site/lib/publicCatalogue.js`, `src/site/widgets/nlCatalogue/` (new); `nlEventList`; registry, loaders, `SITE_COMPOSITIONS`; `WidgetGrid` hands both the portal.
- Tests: PHPUnit `PublicCatalogueTest` (6); node `tests/portal-public-catalogue.spec.mjs` (8, `check:portal-public-catalogue`); `widget-tokens` allow-list.

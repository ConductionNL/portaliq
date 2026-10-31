# Tasks: editor-blocks-read-public-app-data

- [x] 1. Contract: an app's public index declares per kind its categories, filters and columns; normaliser.
  - PHPUnit for the normaliser (`PublicIndexSourceTest`)
- [x] 2. `nlEventList` `source` (app, kind, categories, limit or range), server-side from the cached index.
- [x] 3. `nlPublicTable` block (source, filters with `visitor`, columns) and its site component.
  - `node --test tests/site-public-table.spec.mjs`
- [ ] 4. Palette forms for both, options read from the app's declaration (`src/editor/widgetForms.js`); i18n en and nl. — partial: both blocks are palette widgets with option fields, and `/api/content/catalogue/kinds` plus `fetchCatalogueKinds` serve what an app declares; the palette dropdowns fed from it are not run (the editor form kinds have no option source yet). No new site strings.
- [ ] 5. learniq declares the test schedule and the school-day categories (learniq `portal-public-index`, extended 8 October). — not run: needs learniq

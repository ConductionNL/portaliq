# Tasks: editor-blocks-read-public-app-data

- [x] 1. Contract: an app's public index declares per kind its categories, filters and columns; normaliser.
  - PHPUnit for the normaliser (`PublicIndexSourceTest`)
- [x] 2. `nlEventList` `source` (app, kind, categories, limit or range), server-side from the cached index.
- [x] 3. `nlPublicTable` block (source, filters with `visitor`, columns) and its site component.
  - `node --test tests/site-public-table.spec.mjs`
- [x] 4. Palette forms for both, options read from the app's declaration (`src/editor/widgetForms.js`); i18n en and nl. — `PublicSourcePicker` above the `source` (both blocks) and `columns` (table) fields offers the app and kind, the kind's categories, its filters (any, the visitor's own, or a declared value) and its columns, all from `/api/content/catalogue/kinds`, in the admin designer and on the portal; the JSON field stays for the rest. Pure logic in `src/editor/publicSource.js`; `node --test tests/editor-public-source.spec.mjs` (in `check:specs`). New strings in en and nl.
- [ ] 5. learniq declares the test schedule and the school-day categories (learniq `portal-public-index`, extended 8 October). — not run: needs learniq

# Tasks: editor-blocks-read-public-app-data

- [ ] 1. Contract: an app's public index declares per kind its categories, filters and columns; normaliser.
  - PHPUnit for the normaliser
- [ ] 2. `nlEventList` `source` (app, kind, categories, limit or range), server-side from the cached index.
- [ ] 3. `nlPublicTable` block (source, filters with `visitor`, columns) and its site component.
  - `node --test tests/site-public-table.spec.mjs`
- [ ] 4. Palette forms for both, options read from the app's declaration (`src/editor/widgetForms.js`); i18n en and nl.
- [ ] 5. learniq declares the test schedule and the school-day categories (learniq `portal-public-index`, extended 8 October).

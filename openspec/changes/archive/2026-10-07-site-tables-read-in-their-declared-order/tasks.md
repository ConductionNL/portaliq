# Tasks: site-tables-read-in-their-declared-order

- [x] **T1**: `listOrder(block, collection)` in `src/shared/listWindow.js`; `ContributionPage` orders a table, its cards and its groups by it
  - `node --test tests/mijn-lists.spec.mjs` (`npm run check:mijn-lists`)
- [x] **T2**: a `detail` block under the table of its collection is `quietWhenEmpty`, unless a `citizenCase` block shares the collection; `DetailCard` takes `quietWhenEmpty`
  - `node --test tests/site-collections.spec.mjs` (`npm run check:site-collections`)
- [x] **T3**: `@utrecht/button-link-css` is a dependency; `AccountArea.vue` and `IntakeFormBlock.vue` import it
  - `node --test tests/ways-in-screens.spec.mjs` (`npm run check:ways-in-screens`)
- [x] **T4**: live check on the Wilgenboom portal: the order of the absences, no line under the list, the colour of the sign-in link

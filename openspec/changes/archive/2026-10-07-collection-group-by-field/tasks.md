# Tasks: collection-group-by-field

- [x] **T1**: `src/shared/collectionGroups.js`: group rows by the declared field, name groups from `guardianAudience.children`, render ungrouped below two groups
  - `node --test tests/collection-groups.spec.mjs`
- [x] **T2**: the normaliser keeps `groupByField` only when it names a projected field
  - PHPUnit `PortalManifestNormaliserTest::testAGroupByFieldIsKeptOnlyWhenItNamesAProjectedField`
- [x] **T3**: both renderers (React `PageView.jsx`, site `ContributionPage.vue`) show one table per group under its heading, and load the children's names when a table on the page groups
  - `node --test tests/collection-groups.spec.mjs` (wiring), `node --test tests/site-collections.spec.mjs`
  - Live: Fatima Hulstkamp's grades and attendance in the portal show a heading with Vera's name

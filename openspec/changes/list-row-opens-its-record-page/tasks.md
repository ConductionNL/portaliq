# Tasks: list-row-opens-its-record-page

- [x] 1. `recordPageForRow()` finds the other page that is the row's collection's record page, and `ContributionPage.select()` opens the row there.
  - Files: src/shared/openRecord.js, src/site/pages/collections/ContributionPage.vue
  - Test: tests/open-record.spec.mjs ("a list row opens its collection's record page when that is another page of the app", "the page opens a chosen row on the other page's record route")
- [ ] 2. Live: as an external assessor on the esdoornveen portal, a "Met u gedeeld" row opens the share page with that share's entries (learniq live lane, mbo-assessor-flows / mbo-trainer specs).

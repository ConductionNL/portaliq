# Tasks: catalogue-entry-shows-costs-and-conditions

- [ ] **T01**: `lib/Service/Intake/PortalCatalogueReader.php::topicsFor`: resolve each entry's binding and add `fee` from `PortalFee::forBinding()` (null when none) and `conditions` when it is a list of non-empty strings; PHPUnit for a fee, no fee, a malformed fee, conditions and malformed conditions (REQ-CSC-001).
- [ ] **T02**: `src/site/components/IntakeCatalogueBlock.vue`: the cost line and the conditions list under the summary, formatted with the portal's locale; strings in Dutch and English (REQ-CSC-001).
- [ ] **T03**: Node test `tests/intake-catalogue-costs.spec.mjs`: cost line, no cost line, conditions, malformed conditions.
- [ ] **T04**: Ask opencatalogi, in its issue tracker, whether its `publication` schema can carry `conditions` as a list of strings; until it does, the conditions list stays empty.
- [ ] **T05**: Playwright `tests/e2e/catalogue-entry-shows-costs-and-conditions.spec.ts` and a live check against the PtProducten and PtProduct boards; screenshots in the build PR.
- [ ] **T06**: `openspec validate catalogue-entry-shows-costs-and-conditions --strict`.

# Tasks: case-outcome-on-the-case-page

- [ ] **T01**: `lib/Contribution/CollectionConfigNormaliser.php`: keep `outcome.labelField` and `outcome.descriptionField` only when each names a projected field, as `closedField` is kept; PHPUnit for keep and drop (REQ-COC-001).
- [ ] **T02**: Case screen projection (`CitizenCaseController`, REQ "The case screen receives only the fields its collection declares"): add the declared outcome fields to what the screen receives; PHPUnit (REQ-COC-001).
- [ ] **T03**: `src/site/components/e/CitizenCase.vue`: the "Uitkomst" block above `case-status` for an ended case, with the uuid, URL and `$ref` guard in a small helper under `src/shared/`; strings in Dutch and English (REQ-COC-001).
- [ ] **T04**: Node test `tests/case-outcome.spec.mjs`: ended case with label and explanation, label only, a uuid value, a running case, no declaration.
- [ ] **T05**: Ask dossiq, in its issue tracker, to declare `outcome` on `mijnZaken` with `resultPublicLabel` and `resultPublicDescription`.
- [ ] **T06**: Playwright `tests/e2e/case-outcome-on-the-case-page.spec.ts` and a live check against the ZaakAfgerond board on the Zuiddrecht example site; screenshots in the build PR.
- [ ] **T07**: `openspec validate case-outcome-on-the-case-page --strict`.

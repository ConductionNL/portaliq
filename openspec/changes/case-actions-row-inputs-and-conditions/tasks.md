# Tasks: case-actions-row-inputs-and-conditions

## The manifest

- [ ] **T01**: Normalise `rowInputs`, `availableWhen`, `unavailableReasonField`, `confirmText` and `successText` on endpoint actions, fail closed (REQ-RAI-002, REQ-RAI-004, REQ-RAI-005). Verification: `PortalManifestNormaliserTest::testRowInputsNeedAProjectedField`, `::testAvailableWhenNeedsAScalar`, `::testUnknownKeysAreDropped`.

## The forward

- [ ] **T02**: The row-scoped forward builds `body[into]` only from the names the read row declares, refuses an empty required input with 422, and re-checks `availableWhen` with 409 before forwarding (REQ-RAI-002, REQ-RAI-004). Verification: `ContributionControllerTest::testRowInputUndeclaredNameIsDropped`, `::testRequiredRowInputEmptyIs422AndNotForwarded`, `::testUnavailableRowIs409AndNotForwarded`.

## The screen

- [ ] **T03**: Extract `src/site/components/ActionFields.vue` from the site's `SchemaForm.vue`, use it in `SchemaForm.vue` and in the row action dialog; the React `ActionFieldsForm.jsx` is not ported (REQ-RAI-001). Verification: node test `tests/schema-form.spec.mjs` green; the row action dialog's node test renders a select for a static options provider.
- [ ] **T04**: `src/site/modals/RowActionDialog.vue`: declared fields, row inputs, the confirmation text, the success message, errors under their inputs (REQ-RAI-001, REQ-RAI-002, REQ-RAI-003, REQ-RAI-005). Verification: node test with a stubbed forward returning 200 with a message, 422 with `errors`, and 403.
- [ ] **T05**: `src/site/components/CollectionTable.vue` shows an endpoint row action only where `availableWhen` holds, and the reason elsewhere (REQ-RAI-004). Verification: node test with one available and one unavailable row.
- [ ] **T06**: Playwright `tests/e2e/case-actions-row-inputs-and-conditions.spec.ts` against a fixture contribution with a reason select, a row input and an availability field. Verification: the spec passes in CI.

## Strings, docs and validation

- [ ] **T07**: English and Dutch strings for "Done.", "This could not be done.", "{label}?"; the contributor docs on `rowInputs`, `availableWhen` and `confirmText`; `npm run lint`, `npm run check:manifest`, and `openspec validate case-actions-row-inputs-and-conditions --strict`.

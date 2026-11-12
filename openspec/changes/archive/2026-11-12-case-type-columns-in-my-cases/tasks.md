# Tasks: case-type-columns-in-my-cases

## Backend

- [x] **T1** `CaseTypeColumnsNormaliser`: a field name, a render of `text`, `link`, `qr` or `date`, `linkLabel` of 1 to 60 characters on a link or QR code only, one entry per field, at most 30; `byType()` reads a portal's stored settings. Test: `CaseTypeColumnsNormaliserTest`.
- [x] **T2** Register 0.107.0, portal 0.28.0 `caseTypeColumns`. Test: `PortaliqRegisterConfigTest`.
- [x] **T3** `CaseTypeColumnSettings` (stored settings, declared fields, save of one type merged into the stored portal) and `PortalCaseTypesController::index` / `::updateColumns`, admin-only, route `PUT /api/portals/{slug}/case-types/{typeId}/columns`. Test: `PortalCaseTypesControllerTest`.
- [x] **T4** `ColumnOverrides` and `CaseTypeVisibility::servingColumns`; `ContributionController::screenRows` and `::screenRow` stamp `_columns`. Test: `ColumnOverridesTest`.

## Admin

- [x] **T5** `CaseTypeColumns.vue` with `CnQrCode` preview, opened per case type from `PortalCaseTypes.vue`; logic in `src/lib/caseTypeColumns.js`; Dutch strings. Test: `tests/case-type-columns.spec.mjs`.

## Site

- [x] **T6** `rowColumn()` in `cells.js`; `CollectionTable.vue` and `detailFields()` read a row's `_columns`; `_columns` is envelope. Test: `tests/case-type-columns.spec.mjs`.

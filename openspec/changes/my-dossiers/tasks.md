# Tasks: my-dossiers

- [ ] **T01**: `ItemListConfigNormaliser` wired into the manifest normalisation (REQ-MYD-001). Verification: PHPUnit `ItemListConfigNormaliserTest`.
- [ ] **T02**: `PortalTimelineController::items()`, `PortalItemReader` and the route (REQ-MYD-001, REQ-MYD-002). Verification: PHPUnit `PortalItemListControllerTest`, `PortalItemReaderTest`.
- [ ] **T03**: `ItemList.jsx` in the detail card, with the remove button (REQ-MYD-002, REQ-MYD-003). Verification: `tests/my-dossiers.spec.mjs`.
- [ ] **T04**: `answerLink()` and the link in `RowActionConfirm` (REQ-MYD-004). Verification: `tests/my-dossiers.spec.mjs`.
- [ ] **T05**: English and Dutch strings in `l10n/`. Verification: `npm run test:l10n` if present, `check:l10n-js`.
- [ ] **T06**: `openspec validate my-dossiers --strict`.

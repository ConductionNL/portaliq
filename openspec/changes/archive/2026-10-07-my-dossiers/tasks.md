# Tasks: my-dossiers

- [x] **T01**: `ItemListConfigNormaliser` wired into the manifest normalisation (REQ-MYD-001). Verification: PHPUnit `ItemListConfigNormaliserTest`.
- [x] **T02**: `PortalTimelineController::items()`, `PortalItemReader` and the route (REQ-MYD-001, REQ-MYD-002). Verification: PHPUnit `PortalItemListControllerTest`, `PortalItemReaderTest`.
- [x] **T03**: `ItemList.jsx` in the detail card, with the remove button (REQ-MYD-002, REQ-MYD-003). Verification: `tests/my-dossiers.spec.mjs`.
- [x] **T04**: `answerLink()` and the link in `RowActionConfirm` (REQ-MYD-004). Verification: `tests/my-dossiers.spec.mjs`.
- [x] **T05**: English and Dutch strings in `l10n/`. Verification: `npm run test:l10n` if present, `check:l10n-js`.
- [x] **T06**: `openspec validate my-dossiers --strict`.
- [x] **T07**: `answerLink()` also accepts an absolute link on the page's own origin; opencatalogi's share link on an http instance was dropped and the site said only "Gelukt." (REQ-MYD-004). Verification: `tests/my-dossiers.spec.mjs` ("answer link on the site's own origin").

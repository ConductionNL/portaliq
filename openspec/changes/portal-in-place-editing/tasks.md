# Tasks: portal-in-place-editing

## A1: the designer, the editor core, forms, undo and the version check

- [x] **T01**: The change artifacts (proposal, design, specs, tasks); `openspec validate portal-in-place-editing --strict`.
- [x] **T02**: A markdown page keeps its markdown: `src/editor/pageBody.js` reads a body as `grid` or `markdown` and builds the draft, publish and discard payloads keeping `body.type`; the designer shows the markdown state (REQ-PIE-001). Verification: `tests/page-editor.spec.mjs`, payloads validated against the real `page` schema fragment.
- [x] **T03**: The editor core in `src/editor/` (`gridModel.js`, `pageEditor.js`, `PageGridEditor.vue`, `index.js`), and `PageLayoutDesigner.vue` delegates to it (REQ-PIE-002). Verification: `tests/page-editor.spec.mjs`.
- [x] **T04**: Shared widget forms (`widgetForms.js`, the inspector in `PageGridEditor.vue`) (REQ-PIE-003). Verification: `tests/page-editor.spec.mjs`.
- [x] **T05**: Undo and redo (`editHistory.js`, toolbar buttons, Ctrl+Z, Ctrl+Shift+Z, Ctrl+Y) (REQ-PIE-004). Verification: `tests/page-editor.spec.mjs`.
- [x] **T06**: The version check (`pageSaver.js`: re-read, `If-Match`, 409) and the conflict notice with a reload (REQ-PIE-005). Verification: `tests/page-editor.spec.mjs`.
- [x] **T07**: Dutch and English strings, `npm run lint`, `npm run check:specs`.

## A2: edit mode on the portal

- [x] **T08**: The editing context answers the page id for an editor (it already did: `CmsEditorController::context()`); the editor reads the `updated` marker itself when it loads the page, which is the moment the version check needs. Verification: `CmsEditorControllerTest::testAnEditorGetsTheDesignerLink`, `::testARefusalNamesNoPage`.
- [x] **T09**: `src/editor/geometry.js` used by `WidgetGrid.vue` and the editor (REQ-PIE-008). Verification: `tests/site-edit-mode.spec.mjs`.
- [x] **T10**: "Deze pagina bewerken" in `SiteEditButton.vue` loads the lazily imported `SiteEditMode.vue`, which mounts `PageGridEditor.vue` over the page in the portal theme with the public palette, save draft, publish, discard, history, undo, redo and leave (REQ-PIE-006, REQ-PIE-007). Verification: `tests/site-edit-mode.spec.mjs` and the site build size.

## A3: the rest of the portal from the portal

- [x] **T11**: `parent` and `order` on `page`, register version bump, schema-l10n labels (REQ-PIE-010). Verification: `npm run check:schema-l10n`, `check:register`.
- [x] **T12**: Page management in the edit mode: new page, rename, move, delete a draft page (REQ-PIE-010). Verification: `tests/site-page-tree.spec.mjs`.
- [x] **T13**: Menu editing in the edit mode (REQ-PIE-011). Verification: `tests/site-page-tree.spec.mjs`.
- [x] **T14**: `PageEditorService::applyToSchema()` writes the editor groups into `menu` (REQ-PIE-012). Verification: `PageEditorServiceTest`.

## Archive

- [ ] **T15**: `opsx-archive`: fold the deltas into `openspec/specs/portal-page-designer` and `openspec/specs/portal-in-place-editing`.

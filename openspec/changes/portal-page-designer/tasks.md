# Tasks: portal-page-designer

> Grid page editing for portal pages, reachable from the site (ADR-032 `kind: code`).
> Checkbox budget: 6 tasks × 2 = 12 unindented `- [ ]` lines (cap 20).

## Implementation Tasks

### Task 1: Draft body, editor groups and schema authorization
- **spec_ref**: `openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-a-page-must-carry-a-draft-body-that-is-never-served-publicly`
- **files**: `lib/Settings/portaliq_register.json`, `lib/Service/PageEditorService.php`, `lib/Service/SettingsService.php`, `lib/Service/CmsReader.php`, `tests/Unit/Service/PageEditorServiceTest.php`
- **acceptance_criteria**:
  - The `page` schema carries `draftBody` with the same shape as `body`, and the schema version is bumped
  - `CmsReader` never projects a draft, and the test asserts the leak rather than the shape
  - Editor groups are an app setting; saving them writes the `page` schema's `create`/`update`/`delete` rules and preserves its `read` rules
  - An empty setting restores admin-only writes; it does not leave the schema open to every authenticated user
- [x] Implement
- [x] Test

### Task 2: The editing-context probe
- **spec_ref**: `openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-the-site-must-offer-an-editing-entry-point-only-to-a-visitor-who-may-edit`
- **files**: `lib/Controller/CmsEditorController.php`, `appinfo/routes.php`, `tests/Unit/Controller/CmsEditorControllerTest.php`
- **acceptance_criteria**:
  - A route resolves to `{canEdit, pageId, editUrl}` for a user who may edit that portal's pages
  - A visitor who may not edit gets `canEdit: false` and no page identifier — no existence oracle
  - The route declares its auth posture explicitly and the response is never cached
  - The portal is resolved the same way the content API resolves it, so the answer belongs to the site being viewed
- [x] Implement
- [x] Test

### Task 3: The floating editing control on the site
- **spec_ref**: `openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-the-site-must-offer-an-editing-entry-point-only-to-a-visitor-who-may-edit`
- **files**: `src/site/components/SiteEditButton.vue`, `src/site/App.vue`, `src/site/lib/editorApi.js`
- **acceptance_criteria**:
  - The control is absent from the document for a visitor who may not edit, not merely hidden
  - It is a real button in the bottom-right corner, reachable and operable by keyboard, with its menu dismissible by Escape and its state exposed to assistive technology
  - Its menu offers editing this page, the page listing and page creation, each addressing the app rather than the site bundle
  - The public bundle stays inside the 400 KiB entrypoint budget the site build enforces
- [x] Implement
- [x] Test

### Task 4: The page layout designer
- **spec_ref**: `openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-a-pages-widget-grid-must-be-editable-by-direct-manipulation`
- **files**: `src/views/PageLayoutDesigner.vue`, `src/registry.js`, `src/manifest.json`, `src/store/modules/object.js`
- **acceptance_criteria**:
  - Widgets are added, moved, resized and removed on the shared 12-column grid, keyboard included, and persist in the canonical widget-entry shape
  - The palette offers the whole catalogue and marks every entry the public renderer will not mount, with the reason
  - Save writes the draft, publish promotes it, discard clears it, and each states what it did
  - Writes go through OpenRegister's object API — no CRUD wrapper controller is added
- [x] Implement
- [x] Test

### Task 5: CMS reads immune to a foreign OpenRegister context
- **spec_ref**: `openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-a-cms-read-must-not-inherit-another-apps-openregister-context`
- **files**: `lib/Service/PortalRegisterContext.php`, `lib/Service/CmsReader.php`, `lib/Service/PortalResolver.php`, `tests/Unit/Service/PortalRegisterContextTest.php`
- **acceptance_criteria**:
  - The portal resolver and the CMS reader apply their register/schema context through one helper that resolves the schema entity itself
  - A schema reference left pending by an unrelated caller does not reach this app's reads
  - The schema is resolved as the one THIS app owns, so a slug another app shares cannot be read instead
  - The failure this fixes is stated where it is fixed, with what it looked like from the outside
- [x] Implement
- [x] Test

### Task 6: End-to-end coverage of both surfaces
- **spec_ref**: `openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-a-pages-widget-grid-must-be-editable-by-direct-manipulation`
- **files**: `tests/e2e/site-page-editing.spec.ts`, `tests/e2e/ci-seed.sh`
- **acceptance_criteria**:
  - An anonymous visit shows no editing control; an editor's visit shows it and reaches the designer for that route
  - Moving a widget and saving leaves the public page unchanged; publishing changes it
  - The seed provisions an editor identity, and a failed provision fails loudly rather than degrading the assertions
  - Every scenario in this change's spec is referenced by an `@e2e` tag or carries a reason-bearing exclusion
- [x] Implement
- [x] Test

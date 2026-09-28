# Tasks: site-shared-page-blocks

## The block

- [ ] **T01**: Add schema `sharedBlock` (`title`, `description`, `organisation`, `status`, `widgets`, `draftWidgets`) to `lib/Settings/portaliq_register.json` and the mock register, with Dutch and English labels and a seed block (REQ-SPB-001). Verification: `npm run check:schema-l10n`; the register import test lists the schema.

## The read

- [ ] **T02**: `CmsReader::shapePage()` expands each `sharedBlock` placement into `props.widgets` when the block is published and belongs to the serving portal's organisation, and marks it `unavailable` otherwise (REQ-SPB-002, REQ-SPB-003). Verification: `CmsReaderTest::testSharedBlockExpandsForTheSameOrganisation`, `::testForeignBlockExpandsToNothing`, `::testUnpublishedBlockExpandsToNothing`.
- [ ] **T03**: `CmsCacheInvalidationListener` handles `sharedBlock` writes by clearing every portal of the block's organisation (REQ-SPB-004). Verification: `CmsCacheInvalidationListenerTest::testSharedBlockWriteClearsEveryPortalOfItsOrganisation`.

## The screens

- [ ] **T04**: `WidgetGrid.vue` renders `sharedBlock` as a nested grid over `props.widgets`, and nothing for an unavailable one (REQ-SPB-002). Verification: Vitest `WidgetGrid.spec.js` with an expanded and an unavailable placement.
- [ ] **T05**: "Shared blocks" index and detail in `src/manifest.json`, the designer on `/shared-blocks/:id/layout`, and the "Shared block" palette entry (REQ-SPB-005). Verification: Playwright `tests/e2e/site-shared-page-blocks.spec.ts` edits the seeded block and sees the change on both seeded portals.
- [ ] **T06**: The block detail lists the pages that place it (REQ-SPB-005). Verification: the same Playwright spec reads two pages in the list.

## Rights, strings and validation

- [ ] **T07**: `PageEditorService::applyToSchema()` also writes the `sharedBlock` authorization (REQ-SPB-006). Verification: `PageEditorServiceTest::testEditorGroupsGovernSharedBlocks`.
- [ ] **T08**: English and Dutch strings, an administrator docs page on shared blocks with a screenshot, `npm run lint`, `npm run check:schema-l10n`, `npm run check:manifest`, and `openspec validate site-shared-page-blocks --strict`.

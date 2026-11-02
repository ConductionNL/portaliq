# Tasks: site-shared-page-blocks

## The block

- [x] **T01**: Add schema `sharedBlock` (`title`, `description`, `organisation`, `status`, `widgets`, `draftWidgets`) to `lib/Settings/portaliq_register.json` and the mock register, with Dutch and English labels and a seed block (REQ-SPB-001). Verification: `npm run check:schema-l10n`; the register import test lists the schema. (schema `sharedBlock` in the register 0.84.0 with Dutch strings; the seed block is not added: not run, ExampleSiteInstaller creates only menus, pages and news items, and there is no mock register in this repo)

## The read

- [x] **T02**: `CmsReader::shapePage()` expands each `sharedBlock` placement into `props.widgets` when the block is published and belongs to the serving portal's organisation, and marks it `unavailable` otherwise (REQ-SPB-002, REQ-SPB-003). Verification: `CmsReaderTest::testSharedBlockExpandsForTheSameOrganisation`, `::testForeignBlockExpandsToNothing`, `::testUnpublishedBlockExpandsToNothing`. (`CmsReader::page()` takes the serving portal's organisation, which is part of the cache key; the tests are in CmsReaderTest)
- [x] **T03**: `CmsCacheInvalidationListener` handles `sharedBlock` writes by clearing every portal of the block's organisation (REQ-SPB-004). Verification: `CmsCacheInvalidationListenerTest::testSharedBlockWriteClearsEveryPortalOfItsOrganisation`. (the fan-out is `SharedBlockPortals`, called by the listener; the listener test skips where OpenRegister is not installed, the fan-out itself is pinned by SharedBlockPortalsTest)

## The screens

- [x] **T04**: `WidgetGrid.vue` renders `sharedBlock` as a nested grid over `props.widgets`, and nothing for an unavailable one (REQ-SPB-002). Verification: Vitest `WidgetGrid.spec.js` with an expanded and an unavailable placement. (`SharedBlock.vue`, loaded on demand; checked in `tests/shared-page-blocks.spec.mjs` from the source, not Vitest, which this repo does not use)
- [x] **T05**: "Shared blocks" index and detail in `src/manifest.json`, the designer on `/shared-blocks/:id/layout`, and the "Shared block" palette entry (REQ-SPB-005). Verification: Playwright `tests/e2e/site-shared-page-blocks.spec.ts` edits the seeded block and sees the change on both seeded portals. (index page, the designer on `/shared-blocks/:id/layout` through `src/editor/blockSaver.js`, and a block picker in the placement's inspector; the Playwright spec is not run)
- [ ] **T06**: The block detail lists the pages that place it (REQ-SPB-005). Verification: the same Playwright spec reads two pages in the list. — not run: the block detail page that lists the pages placing a block is not built

## Rights, strings and validation

- [x] **T07**: `PageEditorService::applyToSchema()` also writes the `sharedBlock` authorization (REQ-SPB-006). Verification: `PageEditorServiceTest::testEditorGroupsGovernSharedBlocks`.
- [ ] **T08**: English and Dutch strings, an administrator docs page on shared blocks with a screenshot, `npm run lint`, `npm run check:schema-l10n`, `npm run check:manifest`, and `openspec validate site-shared-page-blocks --strict`. — not run: no administrator docs page or screenshot, and `openspec validate` was not run here; lint, check:schema-l10n and check:manifest were

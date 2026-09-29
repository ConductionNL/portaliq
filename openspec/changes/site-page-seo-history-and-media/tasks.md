# Tasks: site-page-seo-history-and-media

## Search-engine metadata

- [x] **T01**: `page.seo` (title, description, noindex, image) in the register, projected by `CmsReader` and the content API (REQ-SPH-001). Stored flat as `seoTitle`, `seoDescription`, `seoNoindex`, `seoImage` (page 0.4.0, register 0.43.0; see design D2), served as `seo` by `CmsReader::shapeSeo()`.
  - Verify: PHPUnit on `CmsReader` projection; register import on a clean instance
- [x] **T02**: `PortalPageController::site()` resolves the page for `route` through `CmsReader::page()` with the anonymous audience and passes `head`; `templates/site.php` prints title, description, robots, canonical and Open Graph tags (REQ-SPH-001, REQ-SPH-002)
  - Verify: PHPUnit controller test for a public page, a draft (no leak), a gated page (no leak) and an unknown route (`noindex`); a curl of `/site?route=/contact` without JavaScript shows the tags
- [x] **T03**: The SEO section in the page editor, with the length hints (REQ-SPH-001). The four flat fields render in the schema-driven page form with `maxLength` and the hints in their descriptions; no custom editor section.
  - Verify: Playwright `tests/e2e/site-page-seo-history-and-media.spec.ts`: set a description, publish, read it in the served HTML

## History

- [x] **T04**: The History panel over OpenRegister's page audit trail, published versions newest first (REQ-SPH-003). Built as the designer's History dialog (`src/dialogs/PageHistoryDialog.vue`) over `GET /api/pages/{id}/history` (`PageHistoryController`, page editors only) and `lib/Service/Cms/PageHistory.php`, which reads the trail in process (design D3, fixed).
  - Verify: Playwright: publish twice, see two versions with who and when
- [x] **T05**: "Restore this version" copies the version's `body` into `draftBody`; the live page does not change until published (REQ-SPH-003). `restoredDraft()` in `src/lib/pageHistory.js`, written by the designer's own draft write.
  - Verify: Playwright: restore, confirm the public page still shows the newer text, publish, confirm the older text

## Media

- [x] **T06**: `media` schema in `lib/Settings/portaliq_register.json` with file attachment through OpenRegister object files (REQ-SPH-004). media 0.1.0, register 0.44.0; read `authenticated` only (the public reach an item through the content API); the editor groups get its write rules with the page's (`PageEditorService`); the alternative-text rule is portaliq's own (T09 listener), because OpenRegister keeps no `if`/`then`.
  - Verify: register import; PHPUnit on the alt text rule for images
- [x] **T07**: `GET /api/content/media/{id}` streams a published item of the resolved portal, 404 otherwise (REQ-SPH-004). `ContentMediaController` over `lib/Service/Cms/MediaFile.php` (the newest attached file; a portal behind sign-in serves no item, since an image tag carries no bearer token) and `CmsReader::mediaItem()` (published + portal in the query, cached per portal).
  - Verify: PHPUnit for a draft item, another portal's item and an unknown id; `hydra-gate-route-auth` green
- [ ] **T08**: The Media manifest page and the picker dialog in `src/dialogs/`; `heroImage`, `seo.image` and `media:<id>` in markdown resolve to the item (REQ-SPH-004, REQ-SPH-005)
  - Verify: Playwright: upload once, use on two pages, replace the file, both pages show the new one
- [ ] **T09**: Refuse deleting a media item a published page references, naming the pages; invalidate the portal cache on every media write (REQ-SPH-005)
  - Verify: PHPUnit for the refusal and the invalidation

## Close

- [ ] **T10**: Dutch and English strings; editor docs; `openspec validate site-page-seo-history-and-media --strict`

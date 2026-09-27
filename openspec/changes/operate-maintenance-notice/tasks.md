# Tasks: operate-maintenance-notice

## The record

- [ ] **T01**: `portalNotice` schema in `lib/Settings/portaliq_register.json` with a register version bump; `PageEditorService` writes the editor groups into its authorization (REQ-OMN-003). Verification: register import test; `PageEditorServiceTest::testEditorGroupsReachNotices`.
- [ ] **T02**: `lib/Service/PortalNoticeReader.php::active()`: portal, surface, published, window, newest first, at most three (REQ-OMN-001). Verification: `PortalNoticeReaderTest::testDraftIsNotActive`, `::testOutsideWindowIsNotActive`, `::testOtherPortalIsNotActive`.

## Delivery

- [ ] **T03**: `ContentController::site()` adds `notices` for surface `site`; `PortalPageController::index()` adds them to the runtime config for surface `portal` (REQ-OMN-001). Verification: `ContentControllerTest::testSiteCarriesActiveNotices`, `PortalPageControllerTest::testRuntimeConfigCarriesActiveNotices`.

## The screens

- [ ] **T04**: `src/site/components/SiteNotices.vue` and `src/portal/components/PortalNotices.jsx`: `utrecht-alert`, level modifier, labelled section, client-side end check, close for the session (REQ-OMN-001, REQ-OMN-002). Verification: `tests/e2e/operate-maintenance-notice.spec.ts` sees the notice on both applications, closes it, and does not see an expired one.
- [ ] **T05**: `Notices` and `NoticeDetail` pages and the menu entry in `src/manifest.json`, with the start and end check (REQ-OMN-003). Verification: the Playwright spec publishes a notice as a page editor.

## Docs, strings and validation

- [ ] **T06**: English and Dutch strings ("Notice", "Close this notice", "More information", "Notices", "The end must be after the start."); a docs page for editors. Verification: `npm run lint`, `test:l10n`.
- [ ] **T07**: `openspec validate operate-maintenance-notice --strict`.

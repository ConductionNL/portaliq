# Tasks: operate-maintenance-notice

## The record

- [x] **T01**: `portalNotice` schema in `lib/Settings/portaliq_register.json` with a register version bump; `PageEditorService` writes the editor groups into its authorization (REQ-OMN-003). Verification: register import test; `PageEditorServiceTest::testEditorGroupsReachNotices`, `PortaliqRegisterConfigTest::testANoticeNeedsAnEndAndNamesWhereItShows` (Opis on the real fragment).
- [x] **T02**: `lib/Service/PortalNoticeReader.php::active()`: portal, surface, published, window, newest first, at most three (REQ-OMN-001). Verification: `PortalNoticeReaderTest::testDraftIsNotActive`, `::testOutsideWindowIsNotActive`, `::testOtherPortalIsNotActive`, `::testNewestFirstAtMostThreeHttpsLinksOnly`.

## Delivery

- [x] **T03**: `ContentController::site()` adds `notices` for surface `site`; `PortalPageController::index()` adds them to the runtime config for surface `portal` (REQ-OMN-001). Verification: `ContentControllerTest::testSiteCarriesActiveNotices`, `PortalPageControllerTest::testRuntimeConfigCarriesActiveNotices`.

## The screens

- [x] **T04**: `src/site/components/SiteNotices.vue` and `src/portal/components/PortalNotices.jsx`: `utrecht-alert`, level modifier, labelled section, client-side end check, close for the session (REQ-OMN-001, REQ-OMN-002). Verification: `tests/e2e/operate-maintenance-notice.spec.ts` sees the notice on both applications, closes it, and does not see an expired one (written, not run); `tests/notices.spec.mjs` (`npm run check:notices`) renders PortalNotices, checks the end, the close for the visit and the placement in both applications.
- [x] **T05**: `Notices` and `NoticeDetail` pages and the menu entry in `src/manifest.json`, with the start and end check (REQ-OMN-003). Verification: `NoticeWriteGuardListenerTest` (real OpenRegister events); the Playwright spec asserts the refusal.

## Docs, strings and validation

- [x] **T06**: English and Dutch strings ("Notice", "Close this notice", "More information", "Notices", "The end must be after the start."); a docs page for editors. Verification: `npm run lint`, `check:l10n-js`, `check:schema-l10n`. Docs: `docs/operations/maintenance-and-warning-notices.md`.
- [x] **T07**: `openspec validate operate-maintenance-notice --strict`.

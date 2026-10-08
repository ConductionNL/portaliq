# Tasks: site-links-the-theme-bridge

One PR. Build from `origin/development`.

- [x] **T1**: `PortalThemeResolver::shippedStylesheets()['bridge']` is `'public-bridge'` when the theme app has `css/public-bridge.css`, else null (one method since T5, to stay within phpmd) (REQ-STB-001, design D3, D4).
  - PHPUnit `PortalThemeResolverTest::testTheBridgeIsOfferedWhenTheThemeAppShipsIt`, `::testNoBridgeWithoutTheFile`, `::testNoBridgeWithoutAThemeApp`
- [x] **T2**: `PortalPageController` hands `themeBridgeStylesheet` to the site template. `templates/site.php` prepends it to the token layer when `themeStylesheet` is not empty (design D1, D2).
  - A test on the rendered head: bridge after `nlds-app.css` and `site-theme.css`, directly before the set; absent without a set. Done as `tests/site-theme-bridge.spec.mjs` (`npm run check:site-theme-bridge`, in `check:specs`) on the template source, plus PHPUnit `PortalPageControllerTest::testTheBridgeTravelsOnlyWithAResolvedSet`.
  - Mutation: moving the bridge before the vendored sheets, or linking it without a set, fails a test
- [x] **T5**: the theme app's bundled faces (REQ-STB-002, live finding 3 Oct: the example gemeente fell back to Verdana). `PortalThemeResolver::shippedStylesheets()['fonts']` through `ThemeAppAsset`; the controller hands `themeAppSheets` (`bridge`, `fonts`) only with a resolved set; `site.php` links `fonts.css` after this app's faces, before the uploaded faces and the set.
  - PHPUnit `PortalThemeResolverTest::testTheBundledFacesAreOfferedOnlyWhenShipped`, `PortalPageControllerTest::testTheBridgeTravelsOnlyWithAResolvedSet`; `tests/site-theme-bridge.spec.mjs` (guard, order)
  - Guest access checked on :8090 without signing in: `/custom_apps/thematiq/css/public-bridge.css`, `css/fonts.css` and `css/fonts/source-sans-3-latin-400-normal.woff2` answer 200; theme app assets are static files, not routed
- [ ] **T3** (coordinator): Live check on the local instance, recorded in the PR.
  - A portal on `denhaag`: header bar and headings take the set's colours.
  - A portal on `vng`: computed `--utrecht-*` roles unchanged against a capture from before the change.
  - A portal with no theme: no bridge link in the head.
- [x] **T4**: Note in `portal-theme-blocks-and-contributed-pages/tasks.md` task 2 that the bridge link and its order moved to this change.

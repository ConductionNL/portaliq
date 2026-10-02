# Tasks: site-links-the-theme-bridge

One PR. Build from `origin/development`.

- [x] **T1**: `PortalThemeResolver::bridgeStylesheet()` returns `'public-bridge'` when the theme app has `css/public-bridge.css`, else null (REQ-STB-001, design D3, D4).
  - PHPUnit `PortalThemeResolverTest::testTheBridgeIsOfferedWhenTheThemeAppShipsIt`, `::testNoBridgeWithoutTheFile`, `::testNoBridgeWithoutAThemeApp`
- [x] **T2**: `PortalPageController` hands `themeBridgeStylesheet` to the site template. `templates/site.php` prepends it to the token layer when `themeStylesheet` is not empty (design D1, D2).
  - A test on the rendered head: bridge after `nlds-app.css` and `site-theme.css`, directly before the set; absent without a set. Done as `tests/site-theme-bridge.spec.mjs` (`npm run check:site-theme-bridge`, in `check:specs`) on the template source, plus PHPUnit `PortalPageControllerTest::testTheBridgeTravelsOnlyWithAResolvedSet`.
  - Mutation: moving the bridge before the vendored sheets, or linking it without a set, fails a test
- [ ] **T3** (coordinator): Live check on the local instance, recorded in the PR.
  - A portal on `denhaag`: header bar and headings take the set's colours.
  - A portal on `vng`: computed `--utrecht-*` roles unchanged against a capture from before the change.
  - A portal with no theme: no bridge link in the head.
- [x] **T4**: Note in `portal-theme-blocks-and-contributed-pages/tasks.md` task 2 that the bridge link and its order moved to this change.

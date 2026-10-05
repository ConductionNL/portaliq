# Tasks: site chrome follows the design

- [x] 1. Portal schema keys and the `PortalShell` projection (headerSearch, accountLabel, footer.cta, footer.contact, authentication.modeLabels, authentication.signInPage), schema strings in l10n.
- [x] 2. `site.php` and `PortalThemeResolver`: the light logo and the emblem as absolute addresses.
- [x] 3. Motif under the header and over the footer; current item bar (`css/site-theme.css`).
- [x] 4. Designed header: `chrome/HeaderTools.vue` (lazy), BrandHeader wiring, phone menu.
- [x] 5. Designed footer: brand first, button, contact column, darker bottom band.
- [x] 6. Sign-in page as role cards: `chrome/SignInPage.vue` (lazy), AccountArea.
- [x] 7. Light hero with watermark.
- [x] 8. `gridContext().signInRoutes` for lane L2.
- [x] 9. Tests: `tests/site-chrome.spec.mjs`, `PortalShellTest`, `PortalThemeResolverTest`; existing shell tests updated for the new footer keys.
- [x] 10. Live check on :8091 with the four school sets and zuiddrecht (lane folder screenshots).

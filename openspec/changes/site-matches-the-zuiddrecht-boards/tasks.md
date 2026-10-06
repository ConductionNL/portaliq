# Tasks: the public site matches the Zuiddrecht boards

- [x] 1. `nlBanner`: lead, link, kind `notice`, `band`; the grid treats a banner with `band` as a band.
- [x] 2. The hero: `variant: plain`, `popularLabel`, `popularLinks`; the rules in `css/site-theme.css`.
- [x] 3. `nlQuickTasks`: `iconStyle`, `iconPath`, `narrow`, `narrowHeading`, `narrowLimit`.
- [x] 4. `nlNewsList.leadPlaceholder`, `nlLinkList.display` + `intro`, `nlParagraph.lead`, `nlTable.display` + `captionVisible`, `nlButtonLink.icon`.
- [x] 5. `nlLinkColumns` and `nlLookupForm`, registered in loaders, metas and the composition record.
- [x] 6. The breadcrumb reads the header menu's words (`menuLabelFor`).
- [x] 7. `PortalPageController::siteLocale()` holds `Accept-Language` against the portal's `locales`.
- [x] 8. The Zuiddrecht declaration: home and `/afval` per the boards.
- [x] 9. `tests/site-pixel-match.spec.mjs` in `check:specs`; PHPUnit for the locale.
- [x] 10. Documentation: `docs/Installation/example-site-zuiddrecht.md`.
- [x] 11. The install offer as a modal dialog that remembers "Not now" (InstallBanner.vue, tests/install-banner.spec.mjs).
- [x] 12. The Woo pages: `federatedSearch.variant`, `publicationDetail.variant`, the seed's aside, tests/site-woo-pages.spec.mjs (PR 2).
- [ ] 13. Live check on :8097 and the token values in thematiq (coordinator, thematiq lane).

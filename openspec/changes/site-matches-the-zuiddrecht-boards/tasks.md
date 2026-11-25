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
- [ ] 13. Live check on :8097 and the token values in thematiq (coordinator, thematiq lane). — not run: needs a live instance and thematiq lane
  - Live check run 25 Nov 2026 on a sandbox Nextcloud 34.0.6 with thematiq development (`zuiddrecht.css` linked), site against the Home and Contentpagina boards at `ds=zuiddrecht`. Matches: heading face and sizes (Fira Sans, 56 px home, 44 px page), the header bars (rgb 54 105 165 and 35 74 120), the notice, hero, "Veel gezocht", the eight "Direct regelen" tasks, the news list, and on `/afval` the lead, lookup form, container table and "Grof afval". Fixed here: the notice's times read 9:00 tot 13:00 as drawn. Token value to settle in the thematiq lane: heading and text colour compute rgb(27, 28, 29) on the site, rgb(26, 26, 26) on the board.
  - Still different (worklist, so this box stays open): Home lacks the second notice "Storing" (a portalNotice row), "Uittreksel aanvragen" in "Direct regelen" (no page to link), the "Online melden" and "Liever bellen?" blocks, "Uitgelichte onderwerpen" and the live publication counts (home-and-theme-landing-pages widgets, not placed), and the shared contact block; `/afval` lacks "Afvalkalender in uw mailbox". (Compared on the pages' text; the caption the site keeps for screen readers and the side column's place in the source order are not deviations.)

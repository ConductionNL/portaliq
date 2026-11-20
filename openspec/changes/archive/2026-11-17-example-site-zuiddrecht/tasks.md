# Tasks: the Zuiddrecht example site

- [x] 1. Declaration `lib/Settings/sites/zuiddrecht.json` from the design files.
- [x] 2. `ExampleSiteCatalogue`, `ExampleSiteStore`, `ExampleSiteInstaller`, with the read-back proof.
- [x] 3. `occ portaliq:example-site:install` and `:remove`, registered in `appinfo/info.xml`.
- [x] 4. The current menu item in the line (`css/site-theme.css`).
- [x] 5. `nlLinkList` and `nlButtonLink` open pages of the site wherever it is served.
- [x] 6. Tests: `tests/example-site.spec.mjs` in `check:specs`, PHPUnit for the catalogue, installer and commands.
- [x] 7. Documentation: `docs/Installation/example-site-zuiddrecht.md`.
- [x] 8. The sign-in cards of the Inloggen board in the declaration; the own area under the portal's name.
- [x] 9. Live check on a fresh instance (coordinator).
  - Live pass 2026-11-17 (fresh NC 34.0.6, openregister main, thematiq): `occ portaliq:example-site:install zuiddrecht` exits 0 with 1 portal, 3 menus, 50 pages, 4 news items and 6 glossary terms created and found afterwards; `:remove` deletes them all and the portal, and a second install recreates them. The forms row of both reports printed with no label (`form` was missing from the commands' word lists); fixed with `ExampleSiteCommandsTest`. The site renders `/`, `/afval`, `/bestuur/raadsleden`, `/contact/vraag`, `/parkeren`, `/nieuws`, `/toegankelijkheid` and `/veelgestelde-vragen`, and an unknown route shows "Pagina niet gevonden". Left as they are: `/parkeren/productzoeker` reads no finder (the installer seeds no `portalFinder` row, public-faq-and-product-finder T06) and `/zoeken` needs opencatalogi.

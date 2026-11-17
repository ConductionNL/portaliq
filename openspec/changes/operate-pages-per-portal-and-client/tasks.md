# Tasks: operate-pages-per-portal-and-client

## Schemas

- [x] **T01**: Add `portal.navigation` (per audience, an ordered list of `{page, hidden}`) and `portalAccount.hiddenPages` (list of `<app>:<pageId>`) to `lib/Settings/portaliq_register.json` (the mock register holds demo rows only and is left as it is), with Dutch and English labels (REQ-PGC-001, REQ-PGC-002). Verification: `npm run check:schema-l10n`; the self-service PATCH test proves `hiddenPages` is not writable by the account holder.

## The portal choice

- [x] **T02**: `ContributionController::index()` resolves the serving portal and applies its `navigation` list for the subject's audience: hidden pages dropped, listed pages ordered, unlisted pages after them (REQ-PGC-001). Verification: `ContributionControllerTest::testPortalNavigationHidesAndOrdersPages`, `::testUnlistedPageKeepsItsPlace`, `::testNoChoiceAnswersAsToday`.
- [x] **T03**: "Navigation" section on the portal detail page: pages per audience, a show toggle and up and down buttons, saving `portal.navigation` (REQ-PGC-003). Verification: Playwright `tests/e2e/operate-pages-per-portal-and-client.spec.ts` hides a page and sees it gone from the signed-in menu on `/site`. — built: widget `PortalNavigation` in the portal's Instellingen, `GET`/`PUT /api/portals/{slug}/navigation` (admin-only, `PortalNavigationController`, `PortalNavigationCatalogue`); covered by `PortalNavigationCatalogueTest`, `PortalNavigationControllerTest` and `tests/portal-navigation.spec.mjs`. The Playwright spec is not written or run: it needs a live instance.

## The client choice

- [x] **T04**: `PortalContributionRegistry::aggregateFor()` applies `portalAccount.hiddenPages`, drops the collections no remaining page references, and memoises the account read per request (REQ-PGC-002). Verification: `PortalContributionRegistryTest::testHiddenPageForAccountDropsItsCollections`, `ContributionControllerTest::testCollectionOfHiddenPageIsRefusedForThatAccount`.
- [x] **T05**: A "Pages hidden for this client" field on the portal account detail page (REQ-PGC-002). Verification: the same Playwright spec hides the invoices page for one account and sees the other account keep it. — built: widget `PortalAccountPages` on the account page, `GET`/`PUT /api/accounts/{subjectRef}/pages` behind `portal.provision` (`PortalAccountPagesController`, `AccountHiddenPages`); covered by `AccountHiddenPagesTest`, `PortalAccountPagesControllerTest` and `tests/portal-navigation.spec.mjs`. The Playwright spec is not run: it needs a live instance.

## Strings, docs and validation

- [ ] **T06**: English and Dutch strings, an administrator docs page with screenshots, `npm run lint`, `npm run check:schema-l10n`, `npm run check:manifest`, and `openspec validate operate-pages-per-portal-and-client --strict`. — partial: the strings (also for the two widgets), `check:schema-l10n` (no new uncovered strings) and the docs page `docs/operations/pages-per-portal-and-client.md` (text only) are done; eslint is clean on the touched files. Left: the screenshots (a live instance) and `openspec validate` (the openspec CLI). `check:manifest` fails at the base on the Themes page, unrelated to this change.

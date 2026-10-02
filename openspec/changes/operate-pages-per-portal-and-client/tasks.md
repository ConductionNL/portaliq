# Tasks: operate-pages-per-portal-and-client

## Schemas

- [x] **T01** (built: register 0.57.0, portal 0.11.0, portalAccount 0.15.0; the mock register holds only placeholder objects, so the design's seed for open-tilburg is not in it; stored values checked with Opis against both fragments; the self-service test is `PortalSelfServiceServiceTest::testAClientCannotUnhideAPage`, green before and after, as the route never took the field): Add `portal.navigation` (per audience, an ordered list of `{page, hidden}`) and `portalAccount.hiddenPages` (list of `<app>:<pageId>`) to `lib/Settings/portaliq_register.json` and the mock register, with Dutch and English labels (REQ-PGC-001, REQ-PGC-002). Verification: `npm run check:schema-l10n`; the self-service PATCH test proves `hiddenPages` is not writable by the account holder.

## The portal choice

- [x] **T02** (built: the choice is `lib/Contribution/PortalPageChoice::applyNavigation()`; `testUnlistedPageKeepsItsPlace` is in `tests/Unit/Contribution/PortalPageChoiceTest.php` with the ordering cases; contributions are ordered by the first listed position of their pages, so the menu follows the list as far as one app's pages stay together): `ContributionController::index()` resolves the serving portal and applies its `navigation` list for the subject's audience: hidden pages dropped, listed pages ordered, unlisted pages after them (REQ-PGC-001). Verification: `ContributionControllerTest::testPortalNavigationHidesAndOrdersPages`, `::testUnlistedPageKeepsItsPlace`, `::testNoChoiceAnswersAsToday`.
- [ ] **T03**: "Navigation" section on the portal detail page: pages per audience, a show toggle and up and down buttons, saving `portal.navigation` (REQ-PGC-003). Verification: Playwright `tests/e2e/operate-pages-per-portal-and-client.spec.ts` hides a page and sees it gone from the signed-in menu on `/site`.

## The client choice

- [x] **T04** (built: `PortalPageChoice::hideForAccount()`, the account read through `PortalAccountLookup::bySubjectRef()` memoised per subjectRef; only collections the hidden pages showed and no remaining page shows are closed, so an inbox collection on no page stays (design D3); the refusal itself is the existing `authorisedCollection()` path over the registry's aggregate, so it is pinned by `PortalContributionRegistryTest::testHiddenPageForAccountDropsItsCollections` and `::testNoSubjectRefReadsNoAccount`, not by a controller test over a mocked aggregate): `PortalContributionRegistry::aggregateFor()` applies `portalAccount.hiddenPages`, drops the collections no remaining page references, and memoises the account read per request (REQ-PGC-002). Verification: `PortalContributionRegistryTest::testHiddenPageForAccountDropsItsCollections`, `ContributionControllerTest::testCollectionOfHiddenPageIsRefusedForThatAccount`.
- [ ] **T05**: A "Pages hidden for this client" field on the portal account detail page (REQ-PGC-002). Verification: the same Playwright spec hides the invoices page for one account and sees the other account keep it.

## Strings, docs and validation

- [ ] **T06**: English and Dutch strings, an administrator docs page with screenshots, `npm run lint`, `npm run check:schema-l10n`, `npm run check:manifest`, and `openspec validate operate-pages-per-portal-and-client --strict`.

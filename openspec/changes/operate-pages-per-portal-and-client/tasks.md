# Tasks: operate-pages-per-portal-and-client

## Schemas

- [x] **T01**: Add `portal.navigation` (per audience, an ordered list of `{page, hidden}`) and `portalAccount.hiddenPages` (list of `<app>:<pageId>`) to `lib/Settings/portaliq_register.json` (the mock register holds demo rows only and is left as it is), with Dutch and English labels (REQ-PGC-001, REQ-PGC-002). Verification: `npm run check:schema-l10n`; the self-service PATCH test proves `hiddenPages` is not writable by the account holder.

## The portal choice

- [x] **T02**: `ContributionController::index()` resolves the serving portal and applies its `navigation` list for the subject's audience: hidden pages dropped, listed pages ordered, unlisted pages after them (REQ-PGC-001). Verification: `ContributionControllerTest::testPortalNavigationHidesAndOrdersPages`, `::testUnlistedPageKeepsItsPlace`, `::testNoChoiceAnswersAsToday`.
- [ ] **T03**: "Navigation" section on the portal detail page: pages per audience, a show toggle and up and down buttons, saving `portal.navigation` (REQ-PGC-003). Verification: Playwright `tests/e2e/operate-pages-per-portal-and-client.spec.ts` hides a page and sees it gone from the signed-in menu on `/site`. — not run: needs a live instance. The field is editable today through the schema-driven portal form (a JSON widget); the up and down buttons are not built.

## The client choice

- [x] **T04**: `PortalContributionRegistry::aggregateFor()` applies `portalAccount.hiddenPages`, drops the collections no remaining page references, and memoises the account read per request (REQ-PGC-002). Verification: `PortalContributionRegistryTest::testHiddenPageForAccountDropsItsCollections`, `ContributionControllerTest::testCollectionOfHiddenPageIsRefusedForThatAccount`.
- [ ] **T05**: A "Pages hidden for this client" field on the portal account detail page (REQ-PGC-002). Verification: the same Playwright spec hides the invoices page for one account and sees the other account keep it. — not run: needs a live instance. `hiddenPages` is editable through the schema-driven account form; a dedicated field is not built.

## Strings, docs and validation

- [ ] **T06**: English and Dutch strings, an administrator docs page with screenshots, `npm run lint`, `npm run check:schema-l10n`, `npm run check:manifest`, and `openspec validate operate-pages-per-portal-and-client --strict`. — partial: the strings and `check:schema-l10n` are done; the docs page with screenshots and `openspec validate` are not run (a live instance and the openspec CLI).

# Tasks: site-member-voting-record-and-confidential-papers

decidiq's half is `decidiq/portal-voting-record-and-confidential-papers`. Tasks 1 to 3 can be built and tested against a fixture provider; the live walk needs decidiq's half. The live DigiD walk waits on decision D1 (integriq#1495); until then the e2e runs on a stub envelope at trust substantial.

- [ ] **T1**: `publicRecords` in the contract (REQ-SCR-001)
  - Files: `lib/Contribution/IPortalContributionProvider.php` (docblock), `lib/Contribution/PublicRecordsNormaliser.php`, `lib/Contribution/PortalManifestNormaliser.php`, `lib/Contribution/PortalContributionRegistry.php`
  - Done when: an entry with valid providers survives with id, label, group and app and without provider names, in both the anonymous and the subject aggregate; an entry naming a contract method, a non-identifier or a missing method is dropped
  - Test: PHPUnit `PublicRecordsNormaliserTest`
- [ ] **T2**: read public records (REQ-SCR-001, REQ-SCR-006)
  - Files: `lib/Service/PublicRecordReader.php`, `lib/Controller/PublicRecordController.php`, `appinfo/routes.php` (`GET /api/public-records/{app}/{list}`, `GET /api/public-records/{app}/{list}/{id}`, `#[PublicPage]`, `#[AnonRateLimit(limit: 60, period: 60)]`)
  - Done when: the list keeps at most 500 entries and only the contract keys, a record is fetched only for a listed id, an unlisted id is 404 without a provider call, and answers are cached 5 minutes per app and list
  - Test: PHPUnit `PublicRecordReaderTest`, `PublicRecordControllerTest`; route-auth and route-reachability gates
- [ ] **T3**: the public records block (REQ-SCR-005, REQ-SCR-006)
  - Files: `src/site/components/PublicRecordsBlock.vue`, `src/site/components/WidgetGrid.vue`, the widget palette entry, the page editor's config form (list picker from the anonymous aggregate), the server-side block render for `site-honest-without-javascript`, `l10n/*.json`
  - Done when: the list, the search, the record with `KpiCards` and `CollectionTable`, the back link and the stale-link text render per design.md D2, with and without JavaScript
  - Test: `tests/public-records.spec.mjs` (node), then `tests/e2e/site-member-voting-record.spec.ts` against decidiq's seeded council
- [ ] **T4**: documents on any listable collection (REQ-SCR-002, REQ-SCR-007)
  - Files: `lib/Contribution/DocumentsProviderMethod.php`, `lib/Service/CollectionDocuments.php` (extracted), `lib/Service/CitizenCaseDocuments.php`, `lib/Controller/ContributionController.php`, `appinfo/routes.php` (`GET /portal/api/collections/{app}/{collection}/{id}/documents` and `/{documentId}`), `src/site/components/mijn/DocumentsBlock.vue` on the collection detail card
  - Done when: the order in design.md D3 holds; a collection below the session's trust, an object outside the scope and an unlisted document are each 404; case documents keep working unchanged
  - Test: PHPUnit `CollectionDocumentsTest`, the existing `CitizenCaseDocuments` tests still green, `tests/case-documents-screen` check unchanged
- [ ] **T5**: the `opened` hook, fail-closed (REQ-SCR-003)
  - Files: `lib/Contribution/DocumentsProviderMethod.php`, `lib/Service/CollectionDocuments.php`, `l10n/*.json`
  - Done when: the hook runs once before streaming with the session's subjectRef, trust, identity type and audience; false, null or a throw gives 503 "The paper cannot be opened right now" and no bytes
  - Test: PHPUnit `CollectionDocumentsTest` with a provider fixture that returns true, false and throws
- [ ] **T6**: asking for a higher login (REQ-SCR-004)
  - Files: `lib/Controller/ContributionController.php` (`stepUp` in the aggregate), `lib/Contribution/PortalContributionRegistry.php` (collections dropped for trust alone), the contributed page in `src/site` (prompt with the Inloggen board's cards from `LoginProviders`), the return-to-route after sign-in, `l10n/*.json`
  - Done when: `stepUp` holds label and minTrust only; the page shows the prompt with the routes that reach the trust, or "Inloggen met DigiD is hier nog niet beschikbaar" when none does; after signing in the resident lands on the same page
  - Test: PHPUnit on the aggregate, `tests/e2e/confidential-papers.spec.ts`
- [ ] **T7**: example site (REQ-SCR-005)
  - Files: `lib/Service/ExampleSite/ExampleSiteCatalogue.php`
  - Done when: with decidiq installed, Zuiddrecht has "Hoe stemden de raadsleden" under Gemeenteraad with the block on `decidiq` / `memberVotingRecords`; without decidiq the page is not installed
  - Test: `ExampleSiteCatalogueTest`
- [ ] **T8**: verify once before push
  - `composer check:strict`, `npm run lint`, `npm run check:specs`, `npm run test:l10n`, `npm run format`, then the two e2e files on a stub envelope at substantial

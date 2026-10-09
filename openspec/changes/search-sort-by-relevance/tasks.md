# Tasks: search-sort-by-relevance

## The block

- [x] **T01**: `sortOptions()` adds "Meest relevant" when there is a term, and a new search with a term defaults to it; `buildRequestUrl()` adds `_fuzzy=true` for a `_relevance` order (REQ-SSR-001). Verification: Vitest on `federatedSearch.js` and the block. Done: `sortOptionsFor()`, `defaultSortFor()` and `RELEVANCE` in `src/site/lib/federatedSearch.js`, used by `FederatedSearchBlock.vue` (`sortOptions`, `onSearch`, `readLocation`). Tested with node, not Vitest (the site lib has no Vitest harness): `tests/search-relevance.spec.mjs` 'Meest relevant is offered with a term only...'.
- [x] **T02**: The applied-order check, the fallback search, and the one-time message (REQ-SSR-002). Verification: Vitest with a response lacking `@self.relevance`. Done: `relevanceApplied()` plus the fallback in `FederatedSearchBlock.search()`; the notice is said once in the result count's live region. `tests/search-relevance.spec.mjs` 'a response without a score means relevance was not applied'.
- [x] **T03**: The visually hidden match description (REQ-SSR-003). Verification: the Playwright spec reads the accessible description. Done: `matchPercent()` on each result; the result link has `aria-describedby` to a visually hidden 'Overeenkomst: {n} procent'. `tests/search-relevance.spec.mjs` 'the match is a whole percent...'; e2e assertion written in `tests/e2e/search-sort-by-relevance.spec.ts` (not run: needs a dev instance with pg_trgm).

## End to end

- [ ] **T04**: `tests/e2e/search-sort-by-relevance.spec.ts` on an instance with `pg_trgm` and local publications only (the case that works without the opencatalogi sibling half) (REQ-SSR-001). Verification: the first result for the seeded term is the matching publication, not the newest. (not run: needs a dev instance with pg_trgm and seeded local publications; the spec is written in `tests/e2e/search-sort-by-relevance.spec.ts`.)

## Docs, strings and validation

- [x] **T05**: The Dutch label "Meest relevant", "Sorting by relevance is not available here." in English and Dutch, "Match: {n} percent"; update the template comment at lines 130-137 so it no longer says relevance is absent; a docs note on what relevance ranks on. Verification: `npm run lint`, `test:l10n`. Done: Dutch strings in the block (the site block is Dutch throughout, so the notice reads 'Sorteren op relevantie is hier niet beschikbaar.' and the match 'Overeenkomst: {n} procent'; spec amended); the admin text in `l10n/{en,en_US,nl}.json` and the browser catalogues; template comment rewritten; docs note `docs/operations/search-ranking.md`. eslint, prettier and `check:l10n-js` green on the changed files.
- [x] **T06**: `openspec validate search-sort-by-relevance --strict`. Done: valid (9 Oct, lane B6).

## Amendment, 2026-10-05: Woo capability programme (rows 6.14 and 6.17)

Build rules: `openspec/woo-build-rules.md`. A test marked **fails today** must be run
on `origin/development` first and seen red; put the failing line in the PR body.

- [x] **T07**: `buildRequestUrl()` sends `_fuzzy=true` for every non-empty term (REQ-SSR-004).
  Verification: **fails today**, node test `tests/federated-search.spec.mjs`
  `a term always asks for fuzzy matching` and `no term sends no fuzzy flag`. Done: `buildRequestUrl()` sends `_fuzzy=true` with every term, read from `RANKING.fuzzy`. Red then green: `tests/search-relevance.spec.mjs` 'a term always asks for fuzzy matching' (the test file is search-relevance, not federated-search).
- [x] **T08**: `lib/Service/Search/SuggestionWordList.php` builds the per-portal word list from the
  anonymous public search (titles and summaries only), refreshed by a daily `TimedJob`, stored per
  portal in app data. `lib/Service/Search/SpellingSuggester.php` picks the correction by the
  REQ-SSR-005 rule and checks it returns results (REQ-SSR-005). Verification: **fails today**
  (classes absent), `tests/Unit/Service/Search/SpellingSuggesterTest.php`
  `testASummaryWordGetsASuggestion`, `testAShortWordAllowsOneEdit`,
  `testNoSuggestionThatFindsNothing`, `testTheMoreFrequentWordWinsATie`; and
  `SuggestionWordListTest::testADraftNeverReachesTheList`, built on a search double whose
  signature matches the real anonymous search path. Done: `lib/Service/Search/{PublicPublicationSearch,SuggestionWordList,SpellingSuggester}.php`, `lib/BackgroundJob/SuggestionWordListJob.php` (registered in info.xml). Red (classes absent) then green: `SpellingSuggesterTest`, `SuggestionWordListTest::testADraftNeverReachesTheList` over the real PublicPublicationSearch on an InstanceLoopback double with the real `request()` signature, `SuggestionWordListJobTest`.
- [x] **T09**: `SearchSuggestController::suggest()` on GET `/api/site/search/suggest` with
  `#[PublicPage]`, `#[NoCSRFRequired]` and `#[AnonRateLimit]`; the block calls it under three results
  and renders the link in the live region (REQ-SSR-005). Verification: gate route-auth green;
  `tests/Unit/Controller/SearchSuggestControllerTest.php` `testTheRouteAnswersSuggestionAndCount`;
  node test `tests/federated-search.spec.mjs` `fewer than three results asks for a suggestion`. Done: `SearchSuggestController::suggest()` on GET `/api/site/search/suggest` (PublicPage, NoCSRFRequired, AnonRateLimit 60/min); the block asks it under three results and announces the link in the live region. `SearchSuggestControllerTest::testTheRouteAnswersSuggestionAndCount`; node 'fewer than three results asks for a suggestion'.
- [x] **T10**: Export the ranking declaration from `federatedSearch.js`, make `buildRequestUrl()` read
  it, and render "How search ranks" in the portal admin from it (REQ-SSR-006). Verification: node test
  `the request follows the ranking declaration` (change the declaration in the test, the URL
  changes) and `the explanation is rendered from the declaration`. Done: `RANKING` exported from `federatedSearch.js`; `rankingExplanation()` renders it in the `SearchRanking` widget on the portal detail page (`src/manifest.json`, `src/registry.js`). Node 'the request follows the ranking declaration' and 'the explanation is rendered from the declaration'.
- [ ] **T11**: e2e `tests/e2e/search-sort-by-relevance.spec.ts` gains: a misspelt title is found, and a
  misspelt summary word gets a suggestion that finds results (REQ-SSR-004, REQ-SSR-005). Live check
  after merge on the dev instance: record one suggestion and its result count. (not run: the two tests are written in `tests/e2e/search-sort-by-relevance.spec.ts`; the live check needs the dev instance.)
- [ ] **T12**: Before push, once: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then
  `npm run lint`, `npm run format`, `npm run check:l10n-js`, `npm run check:schema-l10n`,
  `npm run check:manifest`, `npm run check:specs` and `npm run build:site`, plus any other leg
  `code-quality.yml` requires. Hydra's `scripts/run-hydra-gates.sh --base origin/development`, gates
  counted. `TMPDIR` a sibling of the clone; PHPUnit judged by the `Tests:` line with `--no-coverage`;
  coverage of added statements projected. One PR, `--base development`, merge never rebase, no
  `Co-Authored-By`. Done means merged on `development` with CI green: 6.14 then reads `yes` (build)
  for titles and the suggestion, 6.17 reads `yes` (build), and `production` only with a store
  release. Say in the PR body that fuzzy matching of summaries and document text waits on an
  OpenRegister change that no plan entry carries.
 (at the checkpoint PR of build/openspecs-6.)
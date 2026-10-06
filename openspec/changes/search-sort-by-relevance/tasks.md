# Tasks: search-sort-by-relevance

## The block

- [ ] **T01**: `sortOptions()` adds "Meest relevant" when there is a term, and a new search with a term defaults to it; `buildRequestUrl()` adds `_fuzzy=true` for a `_relevance` order (REQ-SSR-001). Verification: Vitest on `federatedSearch.js` and the block.
- [ ] **T02**: The applied-order check, the fallback search, and the one-time message (REQ-SSR-002). Verification: Vitest with a response lacking `@self.relevance`.
- [ ] **T03**: The visually hidden match description (REQ-SSR-003). Verification: the Playwright spec reads the accessible description.

## End to end

- [ ] **T04**: `tests/e2e/search-sort-by-relevance.spec.ts` on an instance with `pg_trgm` and local publications only (the case that works without the opencatalogi sibling half) (REQ-SSR-001). Verification: the first result for the seeded term is the matching publication, not the newest.

## Docs, strings and validation

- [ ] **T05**: The Dutch label "Meest relevant", "Sorting by relevance is not available here." in English and Dutch, "Match: {n} percent"; update the template comment at lines 130-137 so it no longer says relevance is absent; a docs note on what relevance ranks on. Verification: `npm run lint`, `test:l10n`.
- [ ] **T06**: `openspec validate search-sort-by-relevance --strict`.

## Amendment, 2026-10-05: Woo capability programme (rows 6.14 and 6.17)

Build rules: `openspec/woo-build-rules.md`. A test marked **fails today** must be run
on `origin/development` first and seen red; put the failing line in the PR body.

- [ ] **T07**: `buildRequestUrl()` sends `_fuzzy=true` for every non-empty term (REQ-SSR-004).
  Verification: **fails today**, node test `tests/federated-search.spec.mjs`
  `a term always asks for fuzzy matching` and `no term sends no fuzzy flag`.
- [ ] **T08**: `lib/Service/Search/SuggestionWordList.php` builds the per-portal word list from the
  anonymous public search (titles and summaries only), refreshed by a daily `TimedJob`, stored per
  portal in app data. `lib/Service/Search/SpellingSuggester.php` picks the correction by the
  REQ-SSR-005 rule and checks it returns results (REQ-SSR-005). Verification: **fails today**
  (classes absent), `tests/Unit/Service/Search/SpellingSuggesterTest.php`
  `testASummaryWordGetsASuggestion`, `testAShortWordAllowsOneEdit`,
  `testNoSuggestionThatFindsNothing`, `testTheMoreFrequentWordWinsATie`; and
  `SuggestionWordListTest::testADraftNeverReachesTheList`, built on a search double whose
  signature matches the real anonymous search path.
- [ ] **T09**: `SearchSuggestController::suggest()` on GET `/api/site/search/suggest` with
  `#[PublicPage]`, `#[NoCSRFRequired]` and `#[AnonRateLimit]`; the block calls it under three results
  and renders the link in the live region (REQ-SSR-005). Verification: gate route-auth green;
  `tests/Unit/Controller/SearchSuggestControllerTest.php` `testTheRouteAnswersSuggestionAndCount`;
  node test `tests/federated-search.spec.mjs` `fewer than three results asks for a suggestion`.
- [ ] **T10**: Export the ranking declaration from `federatedSearch.js`, make `buildRequestUrl()` read
  it, and render "How search ranks" in the portal admin from it (REQ-SSR-006). Verification: node test
  `the request follows the ranking declaration` (change the declaration in the test, the URL
  changes) and `the explanation is rendered from the declaration`.
- [ ] **T11**: e2e `tests/e2e/search-sort-by-relevance.spec.ts` gains: a misspelt title is found, and a
  misspelt summary word gets a suggestion that finds results (REQ-SSR-004, REQ-SSR-005). Live check
  after merge on the dev instance: record one suggestion and its result count.
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

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

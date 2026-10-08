# Tasks: search-suggestions-while-typing

- [ ] **T01**: `src/site/components/SearchSuggestions.vue`: debounced query (3 characters, 250 ms, abort on newer input, 1 s timeout) on the federation endpoint with `_limit=5` and title and kind fields; listbox per the Zoeken board (REQ-SST-001)
- [ ] **T02**: Use it in `FederatedSearchBlock.vue` and the header search box in `BrandHeader.vue`; combobox attributes, keyboard handling, live region text "{n} suggesties"; strings in Dutch and English (REQ-SST-001, REQ-SST-002)
- [ ] **T03**: node test `tests/search-suggestions.spec.mjs`: threshold, debounce, abort, failure, keyboard
- [ ] **T04**: Playwright with axe on the open list; live check against the Zoeken board; screenshot in the build PR

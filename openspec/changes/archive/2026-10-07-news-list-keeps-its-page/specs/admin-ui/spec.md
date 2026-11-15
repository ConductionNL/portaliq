## ADDED Requirements

### Requirement: Saving from a list must keep the list where it was

After staff save, publish or take back a news item from the News page, the list MUST show the changed rows on the same page, with the same sort, filters and search, without reloading the browser page.

#### Scenario: Publishing on page 2
- GIVEN staff are on page 2 of the News list, sorted by title
- WHEN they publish an item on that page
- THEN the list still shows page 2, sorted by title, with the item published
- @e2e exclude pinned by `tests/news-list-refresh.spec.mjs` (the fetch is replayed with the same page, sort and filters); live-checked on the primary-school instance

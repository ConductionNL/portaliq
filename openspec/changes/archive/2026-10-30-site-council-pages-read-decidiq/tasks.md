# Tasks: site-council-pages-read-decidiq

### Task 1: Council pages over decidiq's public record lists
- **spec_ref**: `openspec/changes/site-council-pages-read-decidiq/specs/site-council-records/spec.md#requirement-the-council-pages-show-decidiqs-published-meetings-and-decisions-req-scp-001`
- **files**: `lib/Settings/sites/zuiddrecht.json`, `src/site/components/PublicRecordsBlock.vue`, `tests/Unit/Service/ExampleSite/ExampleSiteCatalogueTest.php`, `tests/public-records.spec.mjs`
- **acceptance_criteria**:
  - GIVEN decidiq WHEN the site is installed THEN `/bestuur/vergaderingen` holds a block on `publicMeetings` and `/bestuur/besluiten` one on `publicDecisions`
  - GIVEN no decidiq WHEN the site is installed THEN the meetings page keeps its text without the block, and no page or link points to `/bestuur/besluiten`
  - GIVEN a `searchLabel` WHEN the block renders its list THEN the field carries that label; without one the block's own words
- [x] Implement
- [x] Test

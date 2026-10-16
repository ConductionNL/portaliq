# site-council-records Specification (delta)

**Status**: planned
**Scope**: portaliq
**OpenSpec changes**:
- [site-council-pages-read-decidiq](../../) (this delta)

## ADDED Requirements

### Requirement: The council pages show decidiq's published meetings and decisions (REQ-SCP-001)

When decidiq is installed, the example site's meetings page MUST hold a public records block on decidiq's `publicMeetings` list and a decisions page MUST hold one on `publicDecisions`, each with a search label that names what it searches. Without decidiq the meetings page MUST keep its text without the block, and the decisions page and every link to it MUST NOT be installed. A page MUST hold at most one public records block.

#### Scenario: A resident looks for the council meeting about the Lindelaan
- GIVEN decidiq published the agenda of the council meeting where the Lindelaan lighting was decided
- WHEN a resident opens "Vergaderingen en stukken"
- THEN she sees the meeting listed, can search for it with "Zoek een vergadering", and opening it shows the agenda
- @e2e exclude pinned by `ExampleSiteCatalogueTest::testTheCouncilPagesReadDecidiqsPublishedRecords`; the block itself is covered by `tests/public-records.spec.mjs`

#### Scenario: A site without decidiq
- GIVEN an instance without decidiq
- WHEN the example site is installed
- THEN "Vergaderingen en stukken" shows its text and no page links to the decisions page
- @e2e exclude pinned by `ExampleSiteCatalogueTest::testWithoutDecidiqTheCouncilPagesKeepTheirText`

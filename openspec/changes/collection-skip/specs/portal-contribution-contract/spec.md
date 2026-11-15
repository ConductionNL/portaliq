## ADDED Requirements

### Requirement: A collection block may leave out its first rows

A collection block MAY declare `skip`, a whole number from 1 to 50. The site MUST leave out that
many rows after the block's order and before its limit, also when the resident opens the whole
list. Any other value MUST be dropped.

#### Scenario: "Daarna" under the next course day
@e2e exclude Unit tests in node: tests/site-look/collection-skip.spec.mjs; PHPUnit CollectionSkipTest
- GIVEN course days on 8, 15, 22 and 29 October, a highlight showing 8 October, and "Daarna" with `skip: 1` and `limit: 2`
- WHEN the overview renders
- THEN "Daarna" lists 15 and 22 October and offers the rest

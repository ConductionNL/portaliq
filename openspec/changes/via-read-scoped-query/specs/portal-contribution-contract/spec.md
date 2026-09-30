## ADDED Requirements

### Requirement: A via read MUST query the subject's own rows and honour the declared filter

In the reverse mode (`match: 'scopeField'`) the outer read MUST ask OpenRegister for rows whose collection `scopeField` equals a verified target, one query per target, so the result does not depend on how many other rows the schema holds. A row returned by two queries MUST be returned once. A collection's declared `filter` MUST narrow the outer read in both modes, and the collection's `scopeField` MUST override any filter entry with the same key. The per-row membership and tenant checks MUST still run on every outer row.

#### Scenario: A child's rows behind a full page of other rows are returned

- GIVEN a schema with 250 rows of other learners before one row of the guardian's child
- WHEN the guardian reads the reverse via collection with the default limit of 200
- THEN the child's row is returned
- @e2e exclude backend query shape, covered by PHPUnit `PortalObjectReaderViaQueryTest` against a fake that filters and pages like OpenRegister

#### Scenario: A report card under review is not shown to a parent

- GIVEN a collection with `filter: {lifecycle: published-to-parents}` and a `via` join
- AND the guardian's child has one `draft` and one `published-to-parents` report card
- WHEN the guardian reads the collection
- THEN only the published report card is returned
- @e2e exclude covered by PHPUnit `PortalObjectReaderViaQueryTest::testReverseViaAppliesTheDeclaredFilter`; exercised live by learniq's po-parent-flows e2e spec

#### Scenario: A filter cannot widen a via read

- GIVEN a declared filter that names the collection's scope field with another learner's reference
- WHEN the guardian reads the collection
- THEN only the guardian's own child's rows are returned
- @e2e exclude covered by PHPUnit `PortalObjectReaderViaQueryTest::testADeclaredFilterOnTheScopeFieldCannotWidenTheVia`

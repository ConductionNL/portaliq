## ADDED Requirements

### Requirement: A guardian's news audience comes from the school app

A contribution serving the `parent` audience MAY declare `guardianAudience` with `children` (a collection id whose rows are the guardian's children), `schoolField` (the child field naming the school) and `groups` (`{collection, field}` naming the children's groups). When the interim fixture holds no row for a guardian, the guardian's audience MUST be read from those collections through the subject-scoped collection reader, with the guardian's own scope claim and via join. A fixture row MUST still take precedence.

#### Scenario: A school-wide news item reaches a guardian of the school
- GIVEN learniq declares `guardianAudience` and a guardian's child belongs to school S
- AND a teacher publishes a news item targeted at school S
- WHEN the guardian opens the news page in the portal
- THEN the item is listed
- @e2e learniq `tests/e2e/po-parent-flows.spec.ts`

#### Scenario: A guardian of another school does not see it
- GIVEN a guardian whose children belong to another school
- WHEN they open the news page
- THEN the item is not listed
- @e2e exclude matching rule unchanged, covered by PHPUnit `NewsAudienceMatcherTest`; the audience source is covered by `LeafGuardianAudienceReaderTest`

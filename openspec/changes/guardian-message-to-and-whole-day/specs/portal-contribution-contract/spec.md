## ADDED Requirements

### Requirement: A message button opens the form with its teacher chosen

A cta block MAY declare `messageTo`, the name of a field of the open record that holds a staff member's reference. Opening such a cta on a record page MUST keep the open record and that staff member for the browser tab, and the messages page MUST, once, open its form with the matching contact chosen in "Aan" and the form in view. When no contact matches both, it MUST choose the record's contact if there is exactly one, and no one otherwise. A `messageTo` that is not a plain field name MUST be dropped.

#### Scenario: Bericht aan meester Daan
- GIVEN Vera's page with a cta `route: /mijn/messages`, label "Bericht aan {teacherName}" and `messageTo: teacherRef`, and Vera's record with `teacherRef` meester Daan
- WHEN her father presses "Bericht aan Meester Daan"
- THEN the messages page opens with "Meester Daan, over Vera" chosen in "Aan"
- AND opening the messages page again later chooses no one
- @e2e exclude pure functions, covered by `node --test tests/site-messages-per-record.spec.mjs` ("Bericht aan meester Daan" opens the form with meester Daan chosen) and PHPUnit `PortalBlockResolverTest::testACtaKeepsWhomItsMessageGoesTo`

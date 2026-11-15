## ADDED Requirements

### Requirement: A dated row may say when its status was set

A collection block with `display: rows` MAY declare `statusNoteAtField`, a date-time field. The line under the status MUST then read the `statusNoteField` value, a comma, and the moment: "vandaag" and the time when it is today, else the day and month and the time ("1 oktober 8:20 uur"). Without a moment the line MUST be the name alone; without a name, the moment alone. The normaliser MUST keep `statusNoteAtField` like the other rows keys.

#### Scenario: Goedgekeurd by the teacher, with the time
- GIVEN Vera's absence report with status "Goedgekeurd", `decidedBy` "Meester Daan" and `decidedAt` 1 October 2026 08:20
- AND the rows block declares `statusNoteField: decidedBy` and `statusNoteAtField: decidedAt`
- WHEN the guardian opens Afwezigheid on 5 October
- THEN the row reads "Goedgekeurd" with "Meester Daan, 1 oktober 8:20 uur" under it, and a report decided that morning reads "Juf Esra, vandaag 8:12 uur"
- @e2e exclude pure function, covered by `node --test tests/site-school-displays.spec.mjs` ("the line under a status says who set it and when") and PHPUnit `SchoolBlockKeysTest::testRowsKeepTheMomentTheStatusWasSet`

### Requirement: A cta label may name any field of the open record

On a record page a cta label MAY hold `{field}` for any field of the open record besides `{title}`, filled as plain text. A cta MAY declare `labelWhenEmpty` (at most 120 characters); when a field its label names is empty on the open record, that label MUST be shown instead. Without it the empty placeholder is left out.

#### Scenario: Bericht aan meester Daan
- GIVEN Vera's page with a cta `route: /mijn/messages`, label "Bericht aan {teacherName}" and `labelWhenEmpty` "Bericht sturen"
- WHEN the guardian opens Vera, whose `teacherName` is "Meester Daan"
- THEN the button reads "Bericht aan Meester Daan"
- AND on the page of a child without a teacher it reads "Bericht sturen"
- @e2e exclude pure function, covered by `node --test tests/mijn-wave6.spec.mjs` ("a cta label names any field of the open record") and PHPUnit `PortalBlockResolverTest::testACtaKeepsTheLabelForAnEmptyField`

## ADDED Requirements

### Requirement: A card may say where its record stands today

A `cards` collection block MAY declare `status`: a collection of the same contribution,
`matchField`, `fromField` and `toField` fields that collection projects, a `label` and `tone`, and
optionally `otherLabel`, `otherTone`, an `only` filter and `schoolDaysOnly`. Portaliq MUST keep the
declaration only when it is whole and well formed, and drop it otherwise. The site MUST show
`label` on a card when a row of that collection whose `matchField` is the card's id, passing
`only`, covers today; else `otherLabel` when declared. It MUST show nothing on a Saturday or Sunday
when `schoolDaysOnly` is set, and nothing while the rows load or after their read failed.

#### Scenario: Reported sick today
@e2e exclude Unit and render tests in node: tests/site-look/card-status.spec.mjs; PHPUnit tests/Unit/Contribution/CardStatusKeysTest.php
- GIVEN Sam's card and a submitted absence report for Sam from 6 to 6 October
- WHEN the guardian opens the overview on Tuesday 6 October
- THEN Sam's card shows "Ziek gemeld" as a warning chip

#### Scenario: Another school day
@e2e exclude Unit test in node: tests/site-look/card-status.spec.mjs
- GIVEN no report for Sam covers today, or only a rejected one
- WHEN the overview opens on a weekday
- THEN Sam's card shows "Op school"

#### Scenario: Weekend
@e2e exclude Unit test in node: tests/site-look/card-status.spec.mjs
- GIVEN `schoolDaysOnly`
- WHEN the overview opens on Saturday
- THEN no card shows a chip

#### Scenario: A half declaration
@e2e exclude PHPUnit tests/Unit/Contribution/CardStatusKeysTest.php
- GIVEN a `status` whose `fromField` the collection does not project
- WHEN the manifest is normalised
- THEN the block has no `status`

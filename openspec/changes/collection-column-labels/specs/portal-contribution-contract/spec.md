## ADDED Requirements

### Requirement: A collection field must read under its schema title when the app gave no label

When a contribution's collection names a schema, the portal MUST give every projected field that
has no label in `fieldConfigs` the schema property's `title` as its label, when that title is a
non-blank string of at most 200 characters. A label the app declared MUST NOT be replaced. A table
header MUST read the column's own label, else the field's label, else the field key written as
words; it MUST NOT show the raw key.

#### Scenario: A table without declared columns
@e2e exclude PHPUnit tests/Unit/Contribution/CollectionSchemaLabelsTest.php and node tests/site-look/column-labels.spec.mjs; live after learniq titles are Dutch
- GIVEN a grades collection on schema `grade-entry` whose property `courseName` has the title "Vak", projecting `courseName` and `value`, with no `columns`
- WHEN the pupil opens the grades table
- THEN the first header reads "Vak", not "courseName" or "Course name"

#### Scenario: The app labelled the field
@e2e exclude PHPUnit tests/Unit/Contribution/CollectionSchemaLabelsTest.php
- GIVEN the same collection with `fieldConfigs.courseName.label` "Onderwerp"
- WHEN the manifest is normalised
- THEN the label stays "Onderwerp"

#### Scenario: No schema to read
@e2e exclude PHPUnit tests/Unit/Contribution/CollectionSchemaLabelsTest.php
- GIVEN a collection whose schema cannot be read
- WHEN the manifest is normalised
- THEN its `fieldConfigs` are unchanged

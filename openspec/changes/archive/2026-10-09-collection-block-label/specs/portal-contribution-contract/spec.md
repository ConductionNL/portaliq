## ADDED Requirements

### Requirement: A collection block keeps its own heading

A collection block MAY declare `label`. Portaliq MUST keep it when it is a non-blank string of at
most 120 characters, and the site MUST head the block's rows with it, before the collection's own
label. A heading equal to the page's title MUST still be left out.

#### Scenario: The grades on the pupil's overview
@e2e exclude Source and method checks in node: tests/site-look/collection-label.spec.mjs; PHPUnit tests/Unit/Contribution/CollectionBlockLabelTest.php
- GIVEN the overview's grades block with `label` "Laatste cijfers" over the collection "Mijn cijfers"
- WHEN the page renders
- THEN the table is headed "Laatste cijfers"

#### Scenario: No label of its own
@e2e exclude Method check in node: tests/site-look/collection-label.spec.mjs
- GIVEN a collection block without `label`
- WHEN the page renders
- THEN the table is headed with the collection's label, as before

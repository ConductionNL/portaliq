## ADDED Requirements

### Requirement: A lookup may be keyed on a field of the row

A block lookup MAY declare `rowField`. The site MUST then match the row's value of that field
against the lookup collection's `matchField`, where `id` names the looked-up row's id, and put the
`valueField` of the matching row under the lookup's `as` name. Without `rowField` the lookup MUST
key on the row's id. A lookup's `as` name MUST count as a field of the block's rows where the block
names fields. A lookup MUST read only a collection of the same contribution.

#### Scenario: An absence report names the child
@e2e exclude Unit tests in node: tests/site-look/lookup-by-row-field.spec.mjs; PHPUnit LookupByRowFieldTest
- GIVEN a report with `learnerRef` `sami` and the guardian's child Sami
- WHEN the reports block with the `childName` lookup renders
- THEN the report reads "Sami"

### Requirement: A task may be titled by a sentence with fields

A tasks block MAY declare `titleTemplate`, at most 200 characters, whose `{name}` places each name
a field of the rows or a lookup. The site MUST title a task with the filled sentence, and with the
joined title fields when a place stays empty.

#### Scenario: The parent-teacher conversation
@e2e exclude Unit tests in node: tests/site-look/lookup-by-row-field.spec.mjs; PHPUnit LookupByRowFieldTest
- GIVEN a conference task for Sami and the template "Kies een tijd voor het oudergesprek van {childName}"
- WHEN the overview renders the task
- THEN the card reads "Kies een tijd voor het oudergesprek van Sami"

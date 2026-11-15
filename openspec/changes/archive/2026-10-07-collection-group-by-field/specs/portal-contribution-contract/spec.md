## ADDED Requirements

### Requirement: A collection MAY group its rows by a declared field

A contribution collection MAY declare `groupByField`, the row field whose value groups the rows. The normaliser MUST keep it only when it is a non-empty string naming one of the collection's projected `fields`, or any field when the collection projects none, and MUST drop it otherwise. When a collection keeps `groupByField` and its rows carry two or more distinct values, the portal MUST show one table per value, each under its own heading. The heading MUST be the name of the row with that id in the contribution's `guardianAudience.children` collection when there is one, else the value itself; rows without a value MUST come last under a heading reading "Other". With fewer than two groups the portal MUST show one table, as without the key.

#### Scenario: A guardian with two children sees one table per child
- GIVEN learniq's `parentGrades` declares `groupByField: 'learnerRef'` and `guardianAudience.children: 'parentChildren'`
- AND a guardian has grades for two children
- WHEN the guardian opens their child's grades
- THEN they see one table per child, each headed by the child's name
- @e2e exclude grouping and naming pinned by `tests/collection-groups.spec.mjs`; the live check on the primary-school instance is in the PR

#### Scenario: One child shows one table
- GIVEN the same collection and a guardian with one child
- WHEN they open the grades
- THEN they see one table without a child heading
- @e2e exclude pinned by `tests/collection-groups.spec.mjs` ("one child, or no group field, renders ungrouped")

#### Scenario: A group field the rows do not carry is dropped
- GIVEN a collection projecting `fields: ['value']` that declares `groupByField: 'learnerRef'`
- WHEN the manifest is normalised
- THEN `groupByField` is dropped
- @e2e exclude pinned by `PortalManifestNormaliserTest::testAGroupByFieldIsKeptOnlyWhenItNamesAProjectedField`

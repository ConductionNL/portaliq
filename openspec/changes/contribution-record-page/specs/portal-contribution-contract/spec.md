## ADDED Requirements

### Requirement: A page MAY be the record page of a collection

A contribution page MAY declare `record` with a `collection` id and optional `titleFields`. The normaliser MUST keep `record` only when its collection resolves in the same contribution, and MUST keep `titleFields` only as a list of non-empty strings. The portal MUST open such a page on the rows of that collection. Choosing a row MUST open the record: a heading with the record's title fields, a way back to the list when there is more than one row, and the page's other blocks. With exactly one row the portal MUST open that record directly. A record link to a row outside the subject's own rows MUST open nothing of it and say so.

#### Scenario: A guardian opens one child
- GIVEN learniq's "Mijn kinderen" page declares `record: {collection: 'parentChildren'}`
- AND a guardian with two children opens it
- WHEN she picks Vera
- THEN she sees a heading "Vera Hulstkamp", a button back to her children, and Vera's blocks
- @e2e exclude rendered by `tests/record-page.spec.mjs`; the live walk through on the primary-school instance is learniq's `tests/e2e/po-parent-flows.spec.ts`

#### Scenario: A record page whose collection is unknown keeps its blocks but loses `record`
- GIVEN a page declaring `record: {collection: 'unknown'}`
- WHEN the manifest is normalised
- THEN the page has no `record` key
- @e2e exclude pinned by `RecordPageNormaliserTest::testARecordIsKeptOnlyWhenItsCollectionResolves`

### Requirement: A block on a record page MAY narrow its rows to the open record

A `collection`, `kpi` or `calendar` block (per calendar source) MAY declare `recordField` and `recordKey` (default `id`). On an open record the portal MUST show only the rows whose `recordField` value equals the record's `recordKey` value, or, when the row holds a list there, contains it. A block or source MAY also declare `recordGroupsField`: a row that names groups there MUST show only for the open record's groups (the rows of the contribution's `guardianAudience.groups` collection that link to the record), or, without an open record, for the groups of every row of that collection; a row that names no group shows for everyone. The narrowing MUST only ever subset the rows the server already scoped to the subject.

#### Scenario: A school trip for another group stays off Vera's page
- GIVEN school events for the whole school, for Vera's group and for another group, and a source with `recordGroupsField: 'cohortIds'`
- WHEN Vera's record is open
- THEN the school-wide event and her group's event show, the other group's does not
- @e2e exclude pinned by `tests/record-page.spec.mjs` ("group-bound rows show for the record's groups")

#### Scenario: Only Vera's report cards show on Vera's page
- GIVEN `parentReportCards` rows for two children and a block with `recordField: 'learnerRef'`
- WHEN Vera's record is open
- THEN only the rows whose `learnerRef` is Vera's id show
- @e2e exclude pinned by `tests/record-page.spec.mjs` ("rows narrow to the open record")

### Requirement: A kpi block MUST show figure cards from one row

A `kpi` block names a collection and `cards`, each with a `field`, a `label`, and optional `unit`, `details` (a list of `{field, label}`) and `highlight`. The normaliser MUST drop a card without a field or label and the block when no card survives. `pick: {field, direction}` chooses the row with the highest (`desc`) or lowest (`asc`) value of that field; without `pick` the first row counts. Without a row the portal MUST say there are no figures yet. An optional `caption: {field, label}` MUST show under the heading which value the cards read (for example the school year). A highlighted card MUST be marked in text, not by colour alone.

#### Scenario: A guardian reads her child's absence figures
- GIVEN an attendance summary row with 5 absent days, 3 with permission and 2 without, and 4 late arrivals of 35 minutes
- WHEN the kpi block renders
- THEN she reads "5 days" with "3 with permission, 2 without permission", "4 times" with "35 minutes", and the unexcused card is marked as needing attention
- @e2e exclude pinned by `tests/record-page.spec.mjs` ("kpi cards")

### Requirement: A calendar block MUST show dated rows as a list and a month

A `calendar` block names `sources`, each with a `collection`, a `startField`, a `titleField` or a fixed `title` (a row without a title value takes the fixed one), an optional `endField`, an optional `kind` label, an optional `only: {field, in}` that keeps only the rows whose field holds one of the listed values, and an optional `expand: {field, startField, endField, titleField}` that turns each element of a list field into its own item. The normaliser MUST drop a source whose collection does not resolve, and the block when no source survives. The portal MUST show the items from today onward as a list grouped by month, and a month view with previous and next buttons, both reachable by keyboard and readable on a phone.

#### Scenario: Holidays, school events and conference times share one calendar
- GIVEN school events, a report period holding holidays, and a booked conference time
- WHEN the guardian opens the calendar
- THEN she sees each as one item with its date and its kind, in date order
- @e2e exclude pinned by `tests/record-page.spec.mjs` ("calendar items")

### Requirement: A news block MUST show the subject's latest news

A `news` block MAY declare `limit` (1 to 20, default 3). The portal MUST show that many of the newest items of the subject's news feed. On an open record it MUST show only items whose target names the record's school (the contribution's `guardianAudience.schoolField`), one of its groups (`guardianAudience.groups`) or the record itself.

#### Scenario: Vera's page shows the news for her school and group
- GIVEN a feed with an item for Vera's school, one for her group and one for another group
- WHEN Vera's record is open
- THEN the news block shows the first two
- @e2e exclude pinned by `tests/record-page.spec.mjs` ("news narrows to the record")

### Requirement: A collection block MAY label its rows from a second collection

A `collection` block MAY declare `lookups`, each with `as`, a `collection` of the same contribution, a `matchField`, a `valueField`, and optional `recordField`, `values` (a map from value to label) and `fallback`. The normaliser MUST drop a lookup that misses a name or whose collection does not resolve. The portal MUST write under `as`, on each row, the `valueField` of the first row of the lookup collection whose `matchField` holds the row's id (narrowed to the open record through `recordField`), labelled through `values`, else `fallback`.

#### Scenario: Homework shows whether the child handed it in
- GIVEN three assignments of Vera's group and her submissions for two of them
- WHEN her homework table renders with a lookup `as: 'status'` over her submissions
- THEN the rows read "Ingeleverd", "Open" and "Te laat ingeleverd"
- @e2e exclude pinned by `tests/record-page.spec.mjs` ("a lookup labels each homework row") and `RecordPageNormaliserTest::testAGroupBoundBlockKeepsItsGroupFieldAndLookups`

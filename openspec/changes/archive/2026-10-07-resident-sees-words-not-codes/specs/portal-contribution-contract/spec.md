## ADDED Requirements

### Requirement: A contributed page may name the menu group it belongs to

A contribution page MAY declare `group`: a short label in the reader's language, sent the same way as the page `label`. The normaliser MUST keep it trimmed when it is a string of 1 to 80 characters, and MUST drop it otherwise, keeping the rest of the page. A group is presentation only and MUST NOT change which pages or blocks a subject may reach.

#### Scenario: A page keeps its group
- GIVEN pipelinq's page `vragen` declares `group: "Vragen en contact"`
- WHEN the manifest is normalised
- THEN the page carries `group: "Vragen en contact"`
- @e2e exclude pinned by `PortalPageGroupTest::testAPageKeepsItsGroup`

#### Scenario: A malformed group is dropped
- GIVEN a page declaring `group` as an empty string, a number, a list or a string over 80 characters
- WHEN the manifest is normalised
- THEN the page has no `group` and keeps its id, label and blocks
- @e2e exclude pinned by `PortalPageGroupTest::testAMalformedGroupIsDropped`

### Requirement: A collection may say how the values of any of its fields read

A collection MAY declare `fieldConfigs`: per field, a `label` and a `valueLabels` map of the same shape a column carries. The normaliser MUST keep a non-blank string label of at most 200 characters and a `valueLabels` map under the column rules, only for a field the collection projects (any field, when it projects none), and MUST drop the key when nothing usable is left. The site MUST use them for a detail field that is no column, and for a column that declares no value labels of its own. A column's own `label` and `valueLabels` MUST win.

#### Scenario: A complaint's category reads in words on the detail card
- GIVEN pipelinq's complaint collection declares `fieldConfigs.complaintCategory: {label: "Soort klacht", valueLabels: {"service": "Dienstverlening"}}`
- AND `complaintCategory` is a detail field and no column
- WHEN the resident opens a complaint with `complaintCategory: service`
- THEN the detail card reads "Soort klacht" and "Dienstverlening"
- @e2e exclude pinned by `tests/value-labels.spec.mjs` ("the detail card shows the fieldConfigs label and value label") and `CollectionFieldConfigsTest::testAFieldKeepsItsLabelAndValueLabels`

#### Scenario: Malformed field configs are dropped
- GIVEN `fieldConfigs` that is no map, an entry for a field the collection does not project, or an entry with a blank label and a malformed map
- WHEN the manifest is normalised
- THEN those entries are dropped, and the key goes when nothing is left
- @e2e exclude pinned by `CollectionFieldConfigsTest::testFieldConfigsAreFailClosed`

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

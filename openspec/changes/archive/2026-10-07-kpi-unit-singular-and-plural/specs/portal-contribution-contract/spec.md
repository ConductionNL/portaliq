## ADDED Requirements

### Requirement: A figure card's unit may name its singular and plural

A `kpi` card's `unit`, and the `label` of each of its `details`, MUST be either a non-empty string or `{one, other}` with both forms non-empty strings, already in the reader's language. The normaliser MUST drop anything else, including half a pair. The portal MUST show `one` beside a figure of exactly 1 and `other` beside every other figure, also when the row holds none. A string MUST read as it is.

#### Scenario: One day reads singular
- GIVEN a card with `unit: {one: "dag", other: "dagen"}` and a row whose figure is 1
- WHEN the guardian opens the child's record page
- THEN the card says "1 dag"
- @e2e exclude pinned by the node test "a kpi card says "1 dag" and "1 minuut", and "5 dagen" beside it"; the live check on :8090 is in the PR

#### Scenario: Any other figure reads plural
- GIVEN the same card and a figure of 0, 5 or none
- WHEN the card renders
- THEN it says "dagen"
- @e2e exclude pinned by the node test "a unit reads singular for one and plural for every other figure"

#### Scenario: Half a pair is dropped
- GIVEN a card with `unit: {one: "dag"}`
- WHEN the contribution is normalised
- THEN the card keeps no unit
- @e2e exclude pinned by `RecordPageNormaliserTest::testAKpiUnitMayNameItsSingularAndPlural`

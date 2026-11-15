## ADDED Requirements

### Requirement: A field may declare a count stepper

An action field config MAY declare `widget: count`. The server MUST keep `min` and `max` only as whole numbers from 0 to 999 with `max` not below `min`, `unit` only with both `one` and `other` as text of at most 60 characters, and `priceLabel` only as text of at most 60 characters. It MUST drop the widget and its companions on a field with an options provider, a date input or a file.

#### Scenario: The employer's booking form keeps its stepper
- **GIVEN** a field declaring `widget: count`, `min: 1`, `max: 12`, `unit: {one: deelnemer, other: deelnemers}`, `priceLabel: [PRIJS]`
- **WHEN** the manifest is normalised
- **THEN** all five keys are kept
- @e2e exclude server normaliser, covered by PHPUnit `ActionConfigNormaliserTest::testACountStepperKeepsWhatFits`

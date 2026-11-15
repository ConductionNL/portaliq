## ADDED Requirements

### Requirement: An action field may ask for a count with a stepper

A field with `widget: count` MUST render as a − button, the number and a + button, the buttons named for a screen reader and disabled at `min` and `max`, with a line under it that a screen reader hears when it changes: the count and the unit in its singular or plural form, and " × " with `priceLabel` when one is declared. The value sent MUST be the whole number between `min` and `max`; with nothing chosen the form MUST send `min`.

#### Scenario: Three participants
- **GIVEN** the stepper starts at 1 with unit deelnemer/deelnemers and price label [PRIJS], max 3
- **WHEN** the employer presses + three times and − once
- **THEN** the line reads "2 deelnemers × [PRIJS]" and the field holds 2
- @e2e exclude component behaviour, covered by `node --test tests/site-count-field.spec.mjs`; the employer's booking form by learniq's proof run

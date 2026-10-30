## ADDED Requirements

### Requirement: A select field shows words for its stored values

A form field MAY declare `optionLabels`, an object from stored option to the words shown. The form block SHALL show those words and SHALL send the stored option, and SHALL show the stored option where no words are declared.

#### Scenario: A Dutch site offers English stored types
@e2e exclude A rendering rule; tests/site-form-option-labels.spec.mjs mounts the block and asserts the shown words and the sent value.

- **GIVEN** a select field with options `Supplier` and `Municipality` and optionLabels `Leverancier` and `Gemeente`
- **WHEN** a visitor opens the form and chooses Leverancier
- **THEN** the form sends `Supplier`

### Requirement: Campaign fields reach only a destination that declares them

A landing-page form's submission SHALL send its campaign fields (`utmFirstTouch`, `utmLastTouch`, `referrer`) only to a destination schema that declares them, so a destination without them does not refuse the whole submission.

#### Scenario: A registration reaches a schema without campaign fields
@e2e exclude A mapping rule; tests/Unit/Service/Intake/DestinationMappingTest.php asserts the fields sent to a destination with and without them.

- **GIVEN** the registration form whose destination is stackiq's organisation schema, which has no campaign fields
- **WHEN** a visitor sends it
- **THEN** the mapping holds the form's own fields and no campaign field

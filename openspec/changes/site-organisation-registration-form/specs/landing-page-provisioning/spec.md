## ADDED Requirements

### Requirement: A select field shows words for its stored values

A form field MAY declare `optionLabels`, an object from stored option to the words shown. The form block SHALL show those words and SHALL send the stored option, and SHALL show the stored option where no words are declared.

#### Scenario: A Dutch site offers English stored types
@e2e exclude A rendering rule; tests/site-form-option-labels.spec.mjs mounts the block and asserts the shown words and the sent value.

- **GIVEN** a select field with options `Supplier` and `Municipality` and optionLabels `Leverancier` and `Gemeente`
- **WHEN** a visitor opens the form and chooses Leverancier
- **THEN** the form sends `Supplier`

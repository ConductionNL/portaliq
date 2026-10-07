## ADDED Requirements

### Requirement: A column and a form field may declare how their values read

A contribution collection column MAY declare `valueLabels`, and so MAY an action's field config: a map from a raw value to the label a resident reads. The normaliser MUST keep only string labels that are not blank, on string or integer keys. It MUST drop a value longer than 100 characters, a label longer than 200, and every entry after the hundredth. It MUST drop the key when nothing usable is left. The site MUST show a column's label for a value in its table cell and on the detail card, and MUST show the value as before when it has no label. When portaliq offers a field's schema `enum` or `oneOf` as a select, a declared label MUST win over the generated label and the `oneOf` title. The option MUST still submit the raw value. A label MUST NOT add a value the schema does not offer.

#### Scenario: A guardian reads the status of an absence report in Dutch
- GIVEN learniq's `parentExcuseRequests` column `lifecycle` declares `valueLabels: {"approved": "Goedgekeurd", "submitted": "Ingediend"}`
- AND a report with `lifecycle: approved`
- WHEN the guardian opens "Afwezigheidsmeldingen van mijn kind" on the site
- THEN the status cell reads "Goedgekeurd"
- AND a status without a label reads as the stored value
- @e2e exclude cell and detail rendering pinned by `tests/value-labels.spec.mjs`; the live check on the primary-school instance is in the PR

#### Scenario: The absence form offers its kinds in Dutch and submits the raw value
- GIVEN the action `createExcuseRequest` field config `reasonKind` declares `valueLabels: {"illness": "Ziekte"}`
- AND the schema property `reasonKind` has `enum: ["illness", "medical-appointment"]`
- WHEN the manifest is normalised
- THEN the select offers `{value: "illness", label: "Ziekte"}` and `{value: "medical-appointment", label: "Medical appointment"}`
- @e2e exclude pinned by `ValueLabelsNormaliserTest::testAFieldsValueLabelsLabelItsEnumOptions`

#### Scenario: A malformed map is dropped
- GIVEN a column declaring `valueLabels: "approved"`, or a map whose labels are not strings
- WHEN the manifest is normalised
- THEN the column keeps its field, label and render, without `valueLabels`
- @e2e exclude pinned by `ValueLabelsNormaliserTest::testAColumnKeepsItsValueLabels` and `::testTheMapIsFailClosed`

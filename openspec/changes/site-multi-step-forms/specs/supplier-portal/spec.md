## MODIFIED Requirements

### Requirement: Form data-minimisation — no non-mandatory field may be required

`PortalManifestNormaliser` MUST NOT let a field config alone make a field required. A field becomes required in exactly two ways: the action's schema requires it, on a create action, or the action names it in `requiredFields` (site-multi-step-forms REQ-SMF-023), which MUST only name fields from the action's own `fields` list. The server MUST refuse an empty required field (REQ-SMF-024), so the form never claims more than portaliq enforces.

#### Scenario: A manifest cannot elevate an optional field to required

- **GIVEN** a `fieldConfigs` entry `{someOptionalField: {required: true}}` where `someOptionalField` is NOT in the action schema's `required` set and NOT in the action's `requiredFields`
- **WHEN** the manifest is normalised
- **THEN** the `required` flag is dropped (the field stays optional) and the rest of the field config survives
- @e2e exclude normalisation guard, covered by PHPUnit (`PortalManifestNormaliserTest::testRequiredIsDroppedOnANonMandatoryField`, `testRequiredIsDroppedWhenSchemaIsUnresolvable`, `testRequiredIsDroppedWhenActionHasNoSchemaKey`); no UI surface

#### Scenario: An unresolvable schema makes nothing required

- **GIVEN** an action whose schema cannot be resolved and whose `fieldConfigs` mark two fields `required: true`, with no `requiredFields`
- **WHEN** the manifest is normalised
- **THEN** both fields lose their `required` flag and stay optional
- @e2e exclude normalisation guard, covered by PHPUnit (`PortalManifestNormaliserTest::testRequiredIsDroppedWhenSchemaIsUnresolvable`); no UI surface

#### Scenario: A genuinely mandatory field keeps its required flag

- **GIVEN** a `fieldConfigs` entry marking `required: true` on a field that IS in the schema's `required` set
- **WHEN** the manifest is normalised
- **THEN** the `required` flag is preserved
- @e2e exclude positive-path normalisation, covered by PHPUnit (`PortalManifestNormaliserTest::testRequiredIsPreservedOnAGenuinelyMandatoryField`); the demo `createExample` action exercises this path live, asserted indirectly by `tests/e2e/wmebv-submission-receipts.spec.ts`

#### Scenario: An action names a required field from its own list

- **GIVEN** an action whose `fields` holds `onderwerp` and whose `requiredFields` names `onderwerp`, while its schema requires nothing
- **WHEN** the manifest is normalised
- **THEN** `onderwerp` carries `required: true`
- @e2e exclude normalisation, covered by PHPUnit `RequiredFieldsNormaliserTest::testTheWooRequestNamesItsFiveFields`

#### Scenario: An action cannot require a field outside its own list

- **GIVEN** an action whose `requiredFields` names `bsn`, which is not in its `fields`
- **WHEN** the manifest is normalised
- **THEN** `bsn` is dropped from `requiredFields` and no field config is added for it
- @e2e exclude normalisation, covered by PHPUnit `RequiredFieldsNormaliserTest::testOnlyTheActionsOwnAskedFieldsCanBeRequired`

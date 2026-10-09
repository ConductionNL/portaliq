# Tasks: site action forms

- [x] 1. `valueType` from the schema type (`SchemaInputHintNormaliser`); typed `formBody`, number check in `fieldErrors`.
- [x] 2. `asksInput` (`src/shared/actionInput.js`); `ActionBlock` draws or opens the form for an endpoint or create action with fields and sends an endpoint form with `forwardAction`.
- [x] 3. `RowActionForm` for an update row action with fields; `onRowAction` opens it.
- [x] 4. `WriteRefusal` and `PortalObjectWriter::lastFailure()`: 422 `invalid` on create and update; the form shows it on the field, else a message by status.
- [x] 5. Tests: `tests/site-action-forms.spec.mjs`; `WriteRefusalTest`; `ContributionControllerRequiredFieldsTest` (422 and 502); `SchemaInputHintNormaliserTest` (valueType).
- [ ] 6. Live check on the proof instance: log hours, approve hours, enrol employees, fill in a birth date, fill in a self-assessment.
- [ ] 7. learniq: `schema` on `approveHourWeek`; endpoint refusals name their fields (see proposal).

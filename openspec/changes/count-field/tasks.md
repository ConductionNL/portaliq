# Tasks: count-field

- [x] 1. `FieldWidgetNormaliser` + `CountWidgetKeys`: keep `widget: count`, `min`, `max`, `unit`, `priceLabel`; drop the widget where it does not fit.
  - PHPUnit `ActionConfigNormaliserTest::testACountStepperKeepsWhatFits`
- [x] 2. `CountStepper.vue`, `countValue()` and `countLine()` in `forms/fields.js`, the branch in `SchemaField.vue`; i18n en and nl.
  - `node --test tests/site-count-field.spec.mjs` (in `check:site-form-fields`)
- [ ] 3. learniq declares it on `enrolEmployees` (learniq `employer-portal-audience`, already declared; shows once this lands).

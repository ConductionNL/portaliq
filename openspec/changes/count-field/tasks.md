# Tasks: count-field

- [x] 1. `FieldWidgetNormaliser` + `CountWidgetKeys`: keep `widget: count`, `min`, `max`, `unit`, `priceLabel`; drop the widget where it does not fit.
  - PHPUnit `ActionConfigNormaliserTest::testACountStepperKeepsWhatFits`
- [x] 2. `CountStepper.vue`, `countValue()` and `countLine()` in `forms/fields.js`, the branch in `SchemaField.vue`; i18n en and nl.
  - `node --test tests/site-count-field.spec.mjs` (in `check:site-form-fields`)
- [x] 3. learniq declares it on `enrolEmployees` (learniq `employer-portal-audience`, already declared; shows once this lands).
  - Checked 17 Nov on learniq development: `lib/Portal/EmployerSitePages.php` `ENROL_EMPLOYEES_FIELD_CONFIGS['participantCount']` declares `widget: count`, `min: 1`, `max: 12` and `unit {one: participant, other: participants}`. No `priceLabel`, as prices are placeholders on the boards (D-11).

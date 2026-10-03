# Tasks: site-multi-step-forms

Four waves. Each wave is one PR to `development`. Every PR runs `npm run build:site` and stays within the 412 KiB budget, and every new string gets nl, en and en_US entries (`npm run check:l10n-js`). Each UI scenario gets a Playwright test under `tests/e2e/` that cites it with `@e2e`, so gate-19 reads the scenario as covered.

## Wave 1: the shared field layer (REQ-SMF-001, REQ-SMF-002, REQ-SMF-003)

- [ ] **T1**: `src/site/components/forms/FieldShell.vue`, `LabelSuffix.vue`, `ErrorSummary.vue`, `DateInputGroup.vue` (design D1 to D4).
  - node tests: the suffix sits inside the label; the summary heading takes focus and each link focuses its field; the date group sends `yyyy-mm-dd` and refuses 31-2-2026
- [ ] **T2**: `IntakeFormBlock.vue`, `c/SchemaForm.vue` + `c/SchemaField.vue` and `FormBlock.vue` use the layer. The `*` goes, `aria-required` comes, the summary replaces `SchemaForm`'s top alert and `focusFirstError()`.
  - Existing suites stay green: `check:schema-form`, `check:schema-form-file-field`, `check:intake-conditional-site` (after #1071), `check:intake-entry`
  - e2e: the absence-form error summary on a phone width, keyboard only

## Wave 2: file input, choice cards, named days (REQ-SMF-004, REQ-SMF-005)

- [ ] **T3**: `FileUpload.vue` in `SchemaField.vue` (design D1). Size hint from the action's declared limit when present.
- [ ] **T4**: `ActionConfigNormaliser` keeps `fieldConfigs.<field>.widget` (`choices`, `dateChoices`), `choiceOptions` (a subset of the options) and `otherLabel` and `dateChoices` count 1 to 5; `ChoiceCards.vue`; the named-day picker in `DateInputGroup.vue` (design D5). Adds `@utrecht/radio-button-css` to the form chunk only.
  - PHPUnit `ActionConfigNormaliserTest::testAWidgetHintIsKeptOnlyWhenKnown`
  - Mutation: dropping the allow-list lets `slider` through and fails the test

## Wave 3: steps, review, confirmation (REQ-SMF-010, REQ-SMF-011, REQ-SMF-020, REQ-SMF-022)

- [ ] **T5**: `PortalFormBindingResolver` passes `steps` (design D6), keeping steps that name known fields and putting loose fields in a last step.
  - PHPUnit `PortalFormBindingResolverTest::testStepsTravelWithTheForm`, `::testAStepNamingAnUnknownFieldIsDropped`, `::testLooseFieldsGetALastStep`
- [ ] **T6**: `FormProgress.vue`, step navigation, per-step validation, skip of all-hidden steps, focus on the step heading, in `IntakeFormBlock.vue`.
- [ ] **T7**: `ReviewList.vue`, the review step with "Wijzigen" links, the confirmation with focus on its heading (design D7).
  - e2e: a four-step form from start to confirmation, keyboard only; the "Stap 2 wijzigen" round trip
- [ ] **T7b**: `ActionConfigNormaliser` keeps `steps`, `draft` and `confirmation` on a create action and on an endpoint action with `fields` (REQ-SMF-020, REQ-SMF-022); `required` stays dropped without a schema (REQ-SMF-023, PHPUnit `::testRequiredWithoutASchemaIsDropped`); `SchemaForm.vue` runs the same step flow; the confirmation fills `{identifier}` and `{deadline}`.
  - PHPUnit `ActionConfigNormaliserTest::testStepsNamingUnknownFieldsAreDropped`, `::testDraftRetentionIsClampedTo1To90`, `::testConfirmationKeepsOnlyText`
  - node test: a confirmation sentence with an empty placeholder is left out

## Wave 4: drafts (REQ-SMF-012, REQ-SMF-021)

- [ ] **T8**: `portalDraft` schema in `lib/Settings/portaliq_register.json`; routes to save, read and delete a draft for the signed-in subject; a purge job; the button and resume landing step in `SchemaForm.vue` (design D8).
  - PHPUnit `PortalDraftControllerTest::testADraftIsOnlyItsOwnersToRead`, `::testSendingDeletesTheDraft`, `::testFileAnswersAreNotKept`; `PortalDraftPurgeJobTest::testAnExpiredDraftIsDeleted`
  - Route auth and IDOR gates green on the new controller
- [ ] **T8b**: After `intake-conditional-questions-and-drafts` T06 lands: the same button and landing step on published forms. Blocked on openregister `or-form-and-journey-registry` tasks 2 and 3.

## Validation

- [ ] **T9**: `openspec validate site-multi-step-forms --strict`

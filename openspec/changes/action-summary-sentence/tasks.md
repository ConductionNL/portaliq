# Tasks: action-summary-sentence

- [x] **T1**: `ActionSummaryNormaliser`, called from `FormStepsNormaliser::applyToAction`; `summary` in `AttachedActionResolver::LISTED`.
  - PHPUnit `ActionConfigNormaliserTest::testASummaryNamesOnlyTheActionsOwnFields`.
- [x] **T2**: `src/site/components/forms/summary.js`; `SchemaForm.vue` shows the sentence above the send button and on the confirmation.
  - node `tests/action-summary-sentence.spec.mjs` (`check:action-summary-sentence`, in `check:specs`).
- [x] **T5**: a date answer reads as "vandaag", "morgen", "gisteren" or "maandag 12 oktober" (request of the learniq lane); node test in `check:action-summary-sentence`.
- [ ] **T3**: the learniq lane declares `summary` on `createExcuseRequest`; live check of the absence form on the primary-school portal next to the MobielDetail board.
- [x] **T4**: `openspec validate action-summary-sentence --strict`

# Tasks: form-flow-repeating-groups-calculations-and-decisions

- [ ] **T01**: `lib/Service/Intake/PortalFormValidator.php`: validate `group` fields as lists (items against sub-fields, `repeat.min` and `repeat.max`); PHPUnit for each refusal (REQ-FFL-001)
- [ ] **T02**: `src/site/components/forms/RepeatingGroup.vue` per the FormulierKaart board: cards, "Wijzigen", "Verwijderen", inline sub-form with "Opslaan" and "Annuleren", add button hidden at max, focus rules of design.md; used by `IntakeFormBlock.vue`; count errors in `ErrorSummary.vue` (REQ-FFL-001)
- [ ] **T03**: `lib/Service/Intake/PortalFormCalculator.php` with the six operations; the submit path overwrites browser values; `PortalFormBindingResolver` refuses a form with an unknown `op`; PHPUnit per operation and for the tampered value (REQ-FFL-002)
- [ ] **T04**: `src/site/components/forms/calculate.js` mirrors the operations for display; `<output>` line in the field layer; node test that site and server agree on the same fixtures (REQ-FFL-002)
- [ ] **T05**: `lib/Service/Intake/PortalFormDecision.php` calls the rule engine's server API; route `POST /api/intake/{route}/steps/{step}/decide` (public like the other intake routes, throttled by `PortalEmbedThrottle`); outcome stored on `portalIntakeSubmission`; PHPUnit with a fake engine, including the engine-down path (REQ-FFL-003)
- [ ] **T06**: `stepFlow.js` follows `nextStep` from the decision's answer; node test (REQ-FFL-003)
- [ ] **T07**: delivery marks calculated and decided fields `computed: true`; PHPUnit on the delivery payload
- [ ] **T08**: strings in nl, en and en_US (`npm run check:l10n-js`); Playwright: the street party form with three neighbours, keyboard only, cites REQ-FFL-001 with `@e2e`
- [ ] **T09**: Live check against the FormulierKaart board; screenshots in the build PR

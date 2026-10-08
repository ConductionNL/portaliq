# Tasks: case-page-tasks-decision-dates-and-next-step

- [ ] **T01**: `lib/Settings/portaliq_register.json`: add `plannedDecisionDate` and `legalDecisionDate` (format date, title and description) to `portalCase` (0.2.0) and `portalStatusActions` (object of `{label, kind, target}`) to `portalCaseType` (0.3.0); import on a dev instance and grep the log for `PARTIAL IMPORT` (REQ-CPT-002, REQ-CPT-003)
- [ ] **T02**: Contribution normaliser accepts `caseField` on a tasks collection and drops it when it does not name a schema property; PHPUnit for keep and drop (REQ-CPT-001)
- [ ] **T03**: `src/site/components/e/CitizenCase.vue`: the task banner above `case-status`, reading the scoped tasks collections filtered on `caseField`; error state text; strings in Dutch and English (REQ-CPT-001)
- [ ] **T04**: `CitizenCase.vue`: the button at the current step and the greyed next step from `portalStatusActions`; hide the button when the target is not available (REQ-CPT-003)
- [ ] **T05**: `CitizenCase.vue` Gegevens rows "Verwacht besluit" and "Uiterlijk klaar op"; `src/site/components/mijn/CaseCard.vue` takes `legalDecisionDate` for its due day (REQ-CPT-002)
- [ ] **T06**: node test `tests/case-page-next-step.spec.mjs` for the banner, the other-case exclusion, the failed read, both date rows and the button rules
- [ ] **T07**: Ask dossiq, in its issue tracker, to write the two dates and declare `caseField` and `portalStatusActions` for its case types
- [ ] **T08**: Live check against the Zaak board on the Zuiddrecht example site; screenshots in the build PR

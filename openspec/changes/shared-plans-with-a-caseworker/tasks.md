# Tasks: shared-plans-with-a-caseworker

- [ ] **T01**: `lib/Settings/portaliq_register.json`: `portalPlan`, `portalPlanTemplate`, `plan` on `portalAction` per design.md "Data"; register bump; import and grep for `PARTIAL IMPORT` (REQ-SPL-001)
- [ ] **T02**: portaliq's contribution declares the `plans` collection scoped on `owner` and `participants`, with create, update and delete actions and the owner-only fields of design.md "Access"; PHPUnit for scope, the contact check and the owner-only fields (REQ-SPL-001, REQ-SPL-002, REQ-SPL-003)
- [ ] **T03**: `src/site/pages/e/PlansPage.vue` and `src/site/modals/PlanStartModal.vue` per the Plannen board; template expansion (actions with dates, end date) on the server; PHPUnit for the dates (REQ-SPL-002)
- [ ] **T04**: `src/site/pages/e/PlanPage.vue` per the Plan board: goal, action table filtered on `plan`, note, files with versions, participants, end date panel; the ActieBewerken dialog of `personal-action-list` with the plan lead and the participants as assignees (REQ-SPL-003)
- [ ] **T05**: plan end reminder in the daily job of `personal-action-list`, template `plan-ending`, `endReminderSentAt` cleared on a new end date; PHPUnit one reminder per end date (REQ-SPL-004)
- [ ] **T06**: PDF route for a participant; IDOR gate green; PHPUnit for refusal to a non-participant (REQ-SPL-005)
- [ ] **T07**: admin page for plan templates (list, edit, publish) in the portal settings
- [ ] **T08**: menu item "Samenwerken" under Mijn Zuiddrecht; done plans hidden a year after `doneAt`
- [ ] **T09**: strings in nl, en, en_US; Playwright: start from a template with a contact, upload a second version, the participant edits an action; scenarios cite REQ-SPL-001 to REQ-SPL-004 with `@e2e`
- [ ] **T10**: Live check against Plannen, Plan and ActieBewerken; screenshots in the build PR

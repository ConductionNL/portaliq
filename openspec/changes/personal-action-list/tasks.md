# Tasks: personal-action-list

- [ ] **T01**: `lib/Settings/portaliq_register.json`: schema `portalAction` per design.md "Data"; register bump; import and grep for `PARTIAL IMPORT` (REQ-RAL-001)
- [ ] **T02**: Portaliq's contribution declares the `actions` collection with scope on `owner` and `assignee`, create, update and delete actions with the field whitelists of design.md "Access"; PHPUnit for scope and for the assignee limits (REQ-RAL-001, REQ-RAL-002)
- [ ] **T03**: `src/site/pages/e/ActionsPage.vue` and `src/site/modals/ActionEditModal.vue` per the ActieBewerken board; file rules; history from the audit trail; strings in Dutch and English (REQ-RAL-001, REQ-RAL-002)
- [ ] **T04**: `lib/BackgroundJob/ActionReminderJob.php` (daily), template `action-due`, `reminderSentAt`; registered in `appinfo/info.xml`; PHPUnit one reminder only (REQ-RAL-003)
- [ ] **T05**: Menu item "Mijn acties" under Mijn Zuiddrecht
- [ ] **T06**: node test `tests/resident-actions.spec.mjs`; Playwright add, edit, share, history
- [ ] **T07**: Live check against the ActieBewerken board; screenshots in the build PR

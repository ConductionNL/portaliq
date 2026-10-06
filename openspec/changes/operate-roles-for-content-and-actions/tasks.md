# Tasks: operate-roles-for-content-and-actions

## Before any code

- [ ] **T01**: On a live instance, as a non-admin user outside every group, try to create a `menu` object through OpenRegister's API. Record what a schema with no write key allows (D4). Verification: the call and result in the PR body.

## The catalogue

- [ ] **T02**: Rewrite `lib/actions.seed.json` as a catalogue of all three checked actions with labels and descriptions, no `$comment`; `InitializeActions` reads both shapes (REQ-ORA-003). Verification: `ActionSeedCensusTest` greps `lib/` for `requireAction(` action names and compares with the seed.
- [x] **T03**: `InitializeActions::run()` adds missing seed actions and keeps stored entries (REQ-ORA-004). Verification: `InitializeActionsTest::testNewSeedActionIsAdded`, `::testStoredGrantIsKept`.
- [x] **T03b**: The six staff authoring controllers check their own action, seeded `["admin"]` (REQ-ORA-006, #1094). Verification: each controller test refuses a user without the action.

## The screen

- [ ] **T04**: `GET` and `PUT /api/settings/actions`, admin-only, sanitising unknown actions and groups (REQ-ORA-001, REQ-ORA-002). Verification: `ActionSettingsControllerTest::testNonAdminIsRefused`, `::testUnknownGroupIsDropped`, `::testUnknownActionIsDropped`.
- [ ] **T05**: The "Actions" section in `src/views/AdminRoot.vue` with one labelled `NcSelect` per action (REQ-ORA-001). Verification: `tests/e2e/operate-roles-for-content-and-actions.spec.ts` grants `portal.provision` to a group and provisions an account as its member.

## Content rights

- [ ] **T06**: `PageEditorService` writes the editor groups into the `menu` schema's authorization (REQ-ORA-005). Verification: `PageEditorServiceTest::testEditorGroupsReachMenu`; the Playwright spec adds a menu entry as an editor.

## Docs, strings and validation

- [ ] **T07**: English and Dutch strings for the section, the three labels and descriptions, and "Only administrators"; a docs page for administrators on roles. Verification: `npm run lint`, `test:l10n`.
- [ ] **T08**: `openspec validate operate-roles-for-content-and-actions --strict`.

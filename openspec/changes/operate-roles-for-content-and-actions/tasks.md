# Tasks: operate-roles-for-content-and-actions

## Before any code

- [ ] **T01**: On a live instance, as a non-admin user outside every group, try to create a `menu` object through OpenRegister's API. Record what a schema with no write key allows (D4). Verification: the call and result in the PR body. — not run: needs a live instance

## The catalogue

- [x] **T02**: Rewrite `lib/actions.seed.json` as a catalogue of all three checked actions with labels and descriptions, no `$comment`; `InitializeActions` reads both shapes (REQ-ORA-003). Verification: `ActionSeedCensusTest` greps `lib/` for `requireAction(` action names and compares with the seed. Built: the seed is a catalogue (`{groups, label, description}`), `InitializeActions` reads both shapes, `ActionSeedCensusTest` compares the `ACTION` constants of every class that calls `requireAction()` with the seed.
- [x] **T03**: `InitializeActions::run()` adds missing seed actions and keeps stored entries (REQ-ORA-004). Verification: `InitializeActionsTest::testNewSeedActionIsAdded`, `::testStoredGrantIsKept`.
- [x] **T03b**: The six staff authoring controllers check their own action, seeded `["admin"]` (REQ-ORA-006, #1094). Verification: each controller test refuses a user without the action.

## The screen

- [x] **T04**: `GET` and `PUT /api/settings/actions`, admin-only, sanitising unknown actions and groups (REQ-ORA-001, REQ-ORA-002). Verification: `ActionSettingsControllerTest::testNonAdminIsRefused`, `::testUnknownGroupIsDropped`, `::testUnknownActionIsDropped`. Built: `lib/Controller/ActionSettingsController.php`, routes `/api/settings/actions`, listed in `AdminOnlyPostureTest`.
- [x] **T05**: The "Actions" section in `src/views/AdminRoot.vue` with one labelled `NcSelect` per action (REQ-ORA-001). Verification: `tests/e2e/operate-roles-for-content-and-actions.spec.ts` grants `portal.provision` to a group and provisions an account as its member. Node test `tests/action-grants.spec.mjs` covers the section's loading and saving; the Playwright spec is not run: needs a live instance.

## Content rights

- [x] **T06**: `PageEditorService` writes the editor groups into the `menu` schema's authorization (REQ-ORA-005). Verification: `PageEditorServiceTest::testEditorGroupsReachMenu`; the Playwright spec adds a menu entry as an editor. Already built: `PageEditorService` follows `page` with `menu`, `media` and `portalNotice` (`PageEditorServiceTest::testTheEditorGroupsAlsoWriteTheMenu`). The Playwright spec is not run: needs a live instance.

## Docs, strings and validation

- [x] **T07**: English and Dutch strings for the section, the three labels and descriptions, and "Only administrators"; a docs page for administrators on roles. Verification: `npm run lint`, `test:l10n`. Strings in `l10n/nl.json`; docs page `docs/operations/roles-for-content-and-actions.md`.
- [x] **T08**: `openspec validate operate-roles-for-content-and-actions --strict` (valid, openspec 1.12.0, 2026-10-09)

## Separate rights

- [x] **T09**: `tests/Unit/Controller/AdminOnlyPostureTest.php` lists every admin settings route, and a test signs in as a page editor who is not an administrator: page, menu, media and notice writes pass, each admin settings route answers 403 (REQ-ORA-007). Built: `testEveryAdminSettingsRouteRefusesAPageEditor` reads `appinfo/routes.php` and checks every route under `/api/settings`, `/api/session-admin/` and `/api/portals/{slug}/`: admin-only (no opt-out), or an opt-out whose method checks an action grant, an administrator or the per-portal admin scope; `settings#index` is the one shared read. The provider gains `settings#load` (now tagged `@auth admin-only`), `actionsettings#byGroup` and the two other session revocations. `PageEditorServiceTest::testAPageEditorHoldsNoAction`: an editor writes menu, media and notices and holds no action.
- [x] **T10**: `PageEditorServiceTest`: a group granted an action but not listed as an editor group is no editor (REQ-ORA-007). Built: `testAnActionGrantMakesNoPageEditor` (the grant works, `mayEdit()` is false, the group reaches no content schema's write rules) and `testAnAdministratorKeepsBothRights`.
- [x] **T11**: `openspec validate operate-roles-for-content-and-actions --strict` after REQ-ORA-007 (valid, openspec 1.12.0, 2026-10-30).

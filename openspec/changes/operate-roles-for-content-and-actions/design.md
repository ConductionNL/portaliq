# Design: operate-roles-for-content-and-actions

Read at portaliq `development` `eeda3fa`.

## What exists

- `lib/Service/ActionAuthService.php:57-99` `requireAction()`: an admin
  always passes; an empty entry or `['admin']` refuses every non-admin; any
  other entry passes a user in one of its groups. The matrix is one
  `IAppConfig` value `actions`, read by `getMatrix()` and written by
  `setMatrix()`.
- `lib/Repair/InitializeActions.php:74-121` seeds the matrix from
  `lib/actions.seed.json` only when it is empty, and otherwise logs
  "preserving".
- `lib/actions.seed.json` holds `portal.provision`, `portal.ask-partner`,
  `portal.review-proposal`, all `['admin']`, and a `$comment` that says
  "Admins customize via Admin Settings > Portaliq > Actions" and "remove the
  $comment field and add real actions when implementing your app".
- Code checks three actions through `requireAction()`: `portal.provision`
  (`lib/Controller/PortalAccountAdminController.php:51`, used at lines 110,
  150, 186, 221), and `portal.ask-partner` and `portal.review-proposal`
  (`lib/Service/Tasks/PortalCaseAccessGuard.php:53,60`, checked at line 115).
  `task.complete` (`lib/Controller/PortalTaskProxyController.php:79`) looks
  like one but is a proof-log `actionId`, not an authorization check.
- `src/views/AdminRoot.vue:104-149` has a "Page editors" section with an
  `NcSelect` of groups, saved through `PUT /api/settings`. There is no
  actions section anywhere in `src/`.
- `lib/Settings/portaliq_register.json`: `page` declares
  `create/update/delete: []` which `PageEditorService` fills with the editor
  groups; `menu` declares only `read: ["public"]`.

## D1. The actions section

A new `NcSettingsSection` "Actions" in `AdminRoot.vue`, after "Page
editors". One row per action from `GET /api/settings/actions`: the label,
the description, and an `NcSelect` (`inputLabel` set) of groups. An empty
picker reads "Only administrators".

New routes, both admin-only in the way `SettingsController::update()` is
(no opt-out attribute; not `AuthorizedAdminSetting`, which would admit
delegated settings admins):

- `GET /api/settings/actions` returns `[{action, label, description,
  groups}]` from the seed's catalogue joined with the stored matrix, plus
  `availableGroups` from `PageEditorService::availableGroups()`.
- `PUT /api/settings/actions` takes `{action: [groupIds]}`, drops any action
  not in the catalogue and any group id that does not exist, and calls
  `setMatrix()`. It never writes `admin` for a user; `admin` stays a display
  hint as `requireAction()` already treats it.

## D2. The seed is a catalogue

`lib/actions.seed.json` becomes:
`{"actions": {"portal.provision": {"groups": ["admin"], "label": "Create and
manage portal accounts", "description": "..."}, ...}}` with all three
actions, and no `$comment`. `ActionAuthService::getMatrix()` keeps reading
the stored group lists only; the labels live in the seed and are read by the
settings route.

## D3. Upgrades add, never overwrite

`InitializeActions::run()` adds every seed action missing from the stored
matrix with its seed groups, and leaves every stored entry as it is. An
action removed from the seed stays stored but is no longer listed.

## D4. Editors reach the menu

`PageEditorService` writes the editor groups into the `menu` schema's
authorization (`create`, `update`, `delete`) as it does for `page`. Task T01
first checks, on a live instance, what OpenRegister does today with a schema
that declares no write key, so the change is measured, not assumed.

## Risks

- **A group granted an action it should not have.** Every save is recorded
  in the Nextcloud audit log through `IAppConfig`, and the section shows the
  current grants on load.
- **The seed's shape changes.** `InitializeActions` accepts both the old list
  form and the new object form for one release.

## What this deliberately does not do

- No new actions. Each future `requireAction()` call site adds its own seed
  entry.
- No change to who may write settings.

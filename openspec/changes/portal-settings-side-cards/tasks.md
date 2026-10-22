# Tasks: portal-settings-side-cards

### Task 1: An administrator switches the Berichtenbox offer from the portal's settings
- **spec_ref**: `openspec/changes/portal-settings-side-cards/specs/portal-settings/spec.md#requirement-an-administrator-switches-the-berichtenbox-offer-from-the-portals-settings`
- **files**: `lib/Service/Notifications/MessageBoxSetting.php`, `lib/Controller/PortalMessageBoxController.php`, `appinfo/routes.php`, `src/widgets/PortalMessageBox.vue`, `src/registry.js`, `src/manifest.json`
- [x] Implement
- [x] Test: `tests/Unit/Service/Notifications/MessageBoxSettingTest.php`, `tests/portal-settings-side-cards.spec.mjs`

### Task 2: An administrator switches whether a portal can be installed
- **spec_ref**: `openspec/changes/portal-settings-side-cards/specs/portal-settings/spec.md#requirement-an-administrator-switches-whether-a-portal-can-be-installed`
- **files**: `lib/Settings/portaliq_register.json`, `lib/Controller/PortalManifestController.php`, `src/manifest.json`
- [x] Implement
- [x] Test: `tests/Unit/Controller/PortalManifestControllerTest.php`, `tests/portal-settings-side-cards.spec.mjs`

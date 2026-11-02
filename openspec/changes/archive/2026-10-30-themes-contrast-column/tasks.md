# Tasks: themes-contrast-column

### Task 1: The themes overview says per portal whether its theme is readable
- **spec_ref**: `openspec/changes/themes-contrast-column/specs/portal-themes/spec.md#requirement-the-themes-overview-says-per-portal-whether-its-theme-is-readable`
- **files**: `lib/Service/Theme/PortalThemeChoice.php`, `lib/Controller/PortalThemeController.php`, `appinfo/routes.php`
- [x] Implement: `PortalThemeChoice::overview()`, `PortalThemeController::portals()`, the route
- [x] Test: `tests/Unit/Service/Theme/PortalThemeOverviewTest.php`

### Task 2: The Themes page shows the contrast, a link and the note
- **spec_ref**: `openspec/changes/themes-contrast-column/specs/portal-themes/spec.md#requirement-the-themes-page-shows-the-contrast-a-link-and-the-note`
- **files**: `src/manifest.json`
- [x] Implement: the `object-table` over the endpoint, the banner under it
- [x] Test: `tests/themes-contrast-column.spec.mjs`

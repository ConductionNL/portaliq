# adopt-connection-registry tasks

## 1. Declare

- [x] 1.1 Write `lib/Settings/connections.json` with `geo-db` and `oidc`.
- [x] 1.2 Give the Visitor geography and Portal auth edge sections the ids the file links to.
- [x] 1.3 Guard the file in `tests/Unit/Settings/ConnectionsDeclarationTest.php`, against a vendored copy of integriq's schema.

## 2. Reports and refresh

- [x] 2.1 Add `lib/Service/Connection/ConnectionObservations.php` and `lib/Service/Connection/ConnectionReporter.php`.
- [x] 2.2 Refresh and report from the geography save in `SettingsService`.
- [x] 2.3 Report the outcome of `GeoRefreshService::refresh()` and a failed open in `MmdbGeoResolver`.
- [x] 2.4 Report broker discovery and code exchange outcomes from `OidcClientService`, throttled.
- [x] 2.5 Add the integriq event stubs for PHPUnit and psalm.
- [x] 2.6 Cover it in `ConnectionReporterTest`, `ConnectionObservationsTest` and `ConnectionReportCallersTest`, which drives each of the four callers.

## 3. Page

- [x] 3.1 Add the `Integrations` page and its settings-gear menu entry to `src/manifest.json`.
- [x] 3.2 Add `src/lib/connectionRegistry.js` with the two formatters and the Add integration handler.
- [x] 3.3 Wire the formatters in `src/App.vue` and the handler in `src/customComponents.js`; register `PowerPlugOutline` in `src/icons.js`.
- [x] 3.4 Add the strings to `l10n/en` and `l10n/nl`.
- [x] 3.5 Cover it in `tests/connection-registry.spec.mjs`.

## 4. End to end

- [x] 4.1 Write `tests/e2e/integrations-page.spec.ts`.
- [x] 4.2 Install integriq in the CI `additional-apps`.

## 5. Switch and built-in formatters (hydra#677)

- [x] 5.1 Declare `switch` on `geo-db` with `offValues: ["none"]`, and stop reporting `unconfigured` for a switched-off geography.
- [x] 5.2 Move `@conduction/nextcloud-vue` to the release with the built-in connection formatters and delete the local copy.

## 6. After integriq ships

- [ ] 6.1 Run the e2e spec against an instance with both apps, then archive this change.

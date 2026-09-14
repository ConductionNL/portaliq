# adopt-connection-registry tasks

## 1. Declare

- [ ] 1.1 Write `lib/Settings/connections.json` with `geo-db` and `oidc`.
- [ ] 1.2 Give the Visitor geography and Portal auth edge sections the ids the file links to.
- [ ] 1.3 Guard the file in `tests/Unit/Settings/ConnectionsDeclarationTest.php`, against a vendored copy of integriq's schema.

## 2. Reports and refresh

- [ ] 2.1 Add `lib/Service/Connection/ConnectionObservations.php` and `lib/Service/Connection/ConnectionReporter.php`.
- [ ] 2.2 Refresh and report from the geography save in `SettingsService`.
- [ ] 2.3 Report the outcome of `GeoRefreshService::refresh()` and a failed open in `MmdbGeoResolver`.
- [ ] 2.4 Report broker discovery and code exchange outcomes from `OidcClientService`, throttled.
- [ ] 2.5 Add the integriq event stubs for PHPUnit and psalm.
- [ ] 2.6 Cover it in `ConnectionReporterTest`, `ConnectionObservationsTest` and one caller test per hook.

## 3. Page

- [ ] 3.1 Add the `Integrations` page and its settings-gear menu entry to `src/manifest.json`.
- [ ] 3.2 Add `src/lib/connectionRegistry.js` with the two formatters and the Add integration handler.
- [ ] 3.3 Wire the formatters in `src/App.vue` and the handler in `src/customComponents.js`; register `PowerPlugOutline` in `src/icons.js`.
- [ ] 3.4 Add the strings to `l10n/en` and `l10n/nl`.
- [ ] 3.5 Cover it in `tests/connection-registry.spec.mjs`.

## 4. End to end

- [ ] 4.1 Write `tests/e2e/integrations-page.spec.ts`.
- [ ] 4.2 Install integriq in the CI `additional-apps`.

## 5. After integriq ships

- [ ] 5.1 Run the e2e spec against an instance with both apps, then archive this change.

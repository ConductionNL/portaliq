# Tasks: row-action-carries-files

- [x] 1.1 `lib/Contribution/RowActionFiles.php`: normalise the `files` declaration and read and check the uploads; called from `RowActionInputs::normaliseAction()` (endpoint row actions only). Verify: `tests/Unit/Contribution/RowActionFilesTest.php`.
- [x] 1.2 `PortalRowActionController::forward()` collects the files after the row inputs, answers 422 `too_many_files` or `file_too_large` before the audit, and hands them to the forwarder. Verify: `PortalRowActionControllerTest::testDeclaredFilesAreForwardedWithTheBody`, `::testTooManyFilesIs422AndNotForwarded` (red on development: dq-l10-logs/pq-red.log).
- [x] 1.3 `PortalActionForwarder::forward()` sends the fields and files multipart when there are files, JSON otherwise. Verify: `PortalActionForwarderTest::testFilesGoMultipartBesideTheFields`, `::testWithoutFilesTheForwardStaysJson`.
- [x] 2.1 `RowActionConfirm.vue` offers the file input ("Bestanden toevoegen", board ZaakWooVerzoek) and checks number and size; `runRowAction()` and `portalApi.forwardRowAction()` send them as `FormData`. Verify: `tests/case-actions-row-inputs.spec.mjs` (four new tests).
- [x] 2.2 en and nl strings, `.js` catalogues regenerated with `scripts/build-l10n-js.js`.
- [ ] 3.1 Live (decision 139): on a dev instance with dossiq, answer a Woo question with one PDF through the portal and see it filed on the case.

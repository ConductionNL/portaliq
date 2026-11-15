# Tasks: resident-pages-live-round-3

- [x] 1.1 `fields.js` `typedDate()`; `DateInputGroup.vue` splits a whole date, keeps digits, moves on after two typed digits, reads a paste before `maxlength` cuts it. Verify: `tests/site-form-fields.spec.mjs`.
- [x] 2.1 `actingFor.js` knows whether a party was chosen; `MyCasesPage.vue` opens on everyone and `readEveryone()` reads the resident first, then each mandate. Verify: `tests/my-cases-parties.spec.mjs`.
- [x] 3.1 `PublicRecordsBlock.vue` back link through `addressOfRoute`. Verify: `tests/public-records.spec.mjs`.
- [x] 3.2 `PublicationDetailBlock.vue` emits `subject` with its title. Verify: `tests/publication-documents.spec.mjs`.
- [x] 4.1 `SchemaForm.canSaveDraft` requires a create action with register and schema; `PortalDraftController::show()` answers 200 `{found: false}` for no draft; `portalApi.getDraft()` reads it as none. Verify: `tests/site-form-drafts.spec.mjs`, `PortalDraftControllerTest`.
- [ ] 5.1 Live (decision 139): on :8080 paste a date into a Woo form, open Mijn zaken as Linda, follow "Alle vergaderingen", open a publication, and see no drafts 404 in the console.

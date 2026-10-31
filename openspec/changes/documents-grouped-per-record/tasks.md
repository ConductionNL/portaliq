# Tasks: documents-grouped-per-record

- [ ] 1. Normaliser: keep `groupBy`, `extraGroups`, `note`, `bulkDownload`, `upload` on a documents block; drop what does not fit. — partial: `groupBy`, `note` and `upload` are kept (`BoardKeysTest`); `extraGroups` and `bulkDownload` are not run: they need the media audience read (task 3) and a provider bundle endpoint.
  - PHPUnit for the normaliser
- [ ] 2. `DocumentsBlock.vue`: group headings, the media group for the audience, "Nieuw" badge, status pill, note, bundle button, upload action. — partial: group headings, "Nieuw" badge, status pill, provider line, note and the existing upload are done; the media group and the bundle button are not run (tasks 1 and 3).
  - `node --test tests/site-documents-groups.spec.mjs`
- [ ] 3. Media read for the signed-in audience (only media whose audience matches). — not run: `media` has no audience field and portaliq has no authenticated media read; needs a schema and endpoint decision first.
- [ ] 4. Editor form for the new options in `src/editor/widgetForms.js`; i18n en and nl. — partial: i18n is done (the keys already exist); the editor form is not run because a documents block is declared in a contribution manifest, not placed by the page editor.
- [ ] 5. learniq places the block on the four Documenten pages (learniq `school-documents-on-the-portal`). — not run: needs learniq

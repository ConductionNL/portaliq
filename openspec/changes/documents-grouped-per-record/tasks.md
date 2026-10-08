# Tasks: documents-grouped-per-record

- [ ] 1. Normaliser: keep `groupBy`, `extraGroups`, `note`, `bulkDownload`, `upload` on a documents block; drop what does not fit.
  - PHPUnit for the normaliser
- [ ] 2. `DocumentsBlock.vue`: group headings, the media group for the audience, "Nieuw" badge, status pill, note, bundle button, upload action.
  - `node --test tests/site-documents-groups.spec.mjs`
- [ ] 3. Media read for the signed-in audience (only media whose audience matches).
- [ ] 4. Editor form for the new options in `src/editor/widgetForms.js`; i18n en and nl.
- [ ] 5. learniq places the block on the four Documenten pages (learniq `school-documents-on-the-portal`).

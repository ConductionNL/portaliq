# Tasks: publication-page-shows-the-schemas-fields

- [x] **T1**: `schemaRows()` turns the other fields of a non-Woo publication into rows named, ordered and formatted by `@self.schema`, leaving out `visible: false`, empty values, files and related objects known only by their id; without the schema the field name is put in words (`src/site/lib/publicationDetail.js`)
  - `node --test tests/publication-documents.spec.mjs`
- [x] **T2**: the summary falls back to `shortDescription`; `longDescriptionLines()` gives the long description as text lines
  - `node --test tests/publication-documents.spec.mjs`
- [x] **T3**: the publication page asks for `_extend=@self.schema`, renders the rows after the Woo rows and the long description under the summary, as text (`src/site/components/PublicationDetailBlock.vue`)
  - `node --test tests/publication-documents.spec.mjs`
- [x] **T4**: a Woo publication reads as before
  - `node --test tests/publication-documents.spec.mjs`, `node --test tests/site-woo-pages.spec.mjs`
- [x] **T5**: a publication whose schema describes `wooCategory` counts as Woo without the key; a markdown value reads list items, links, emphasis and code as text
  - `node --test tests/publication-documents.spec.mjs`

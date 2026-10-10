# Tasks: help-texts-and-form-help

- [x] **T01**: `lib/Settings/portaliq_register.json`: `portal.help`, `form.help`, `page.helpText`, `portal.sectionHelp` per design.md "Data"; version bumps; import and grep for `PARTIAL IMPORT` (REQ-HTF-001, REQ-HTF-002) — the import grep is not run: needs a live instance. The portal, form and page versions are bumped.
- [ ] **T02**: (partial: `FormHelpModal.vue`, `FormHelp.vue` and the merge are built and sit in `FormBlock`; `portal.help` reaches the form through the grid. The form-level `help` is a prop of the block, but nothing yet carries `form.help` from the stored form to the placement, and the multi-step intake header has no link yet) `src/site/modals/FormHelpModal.vue` per the FormulierHulp board and the "Hulp nodig?" link in the multi-step form header; merge of form and portal help per key; focus return; strings in Dutch and English (REQ-HTF-001)
- [x] **T03**: `src/site/components/PageHelp.vue` disclosure under the heading of CMS pages and the Mijn omgeving pages (REQ-HTF-002)
- [ ] **T04**: (not run: the fields are editable through the schema-driven portal, form and page forms as JSON and text; dedicated settings pages PtPortalInstellingen and PtFormulierInstellingen and the editor field are not built) Admin: help details on the portal settings page and the form settings page (PtPortalInstellingen, PtFormulierInstellingen); help text on the page editor
- [x] **T05**: node tests `tests/form-help.spec.mjs` (answers kept, override, hidden without details) and `tests/page-help.spec.mjs`
- [x] **T06**: Zuiddrecht example site sets portal help details and a help text on Mijn taken
- [ ] **T07**: (not run: needs a live instance and the board) Live check against the FormulierHulp board; screenshots in the build PR

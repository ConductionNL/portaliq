# Tasks: help-texts-and-form-help

- [ ] **T01**: `lib/Settings/portaliq_register.json`: `portal.help`, `form.help`, `page.helpText`, `portal.sectionHelp` per design.md "Data"; version bumps; import and grep for `PARTIAL IMPORT` (REQ-HTF-001, REQ-HTF-002)
- [ ] **T02**: `src/site/modals/FormHelpModal.vue` per the FormulierHulp board and the "Hulp nodig?" link in the multi-step form header; merge of form and portal help per key; focus return; strings in Dutch and English (REQ-HTF-001)
- [ ] **T03**: `src/site/components/PageHelp.vue` disclosure under the heading of CMS pages and the Mijn omgeving pages (REQ-HTF-002)
- [ ] **T04**: Admin: help details on the portal settings page and the form settings page (PtPortalInstellingen, PtFormulierInstellingen); help text on the page editor
- [ ] **T05**: node tests `tests/form-help.spec.mjs` (answers kept, override, hidden without details) and `tests/page-help.spec.mjs`
- [ ] **T06**: Zuiddrecht example site sets portal help details and a help text on Mijn taken
- [ ] **T07**: Live check against the FormulierHulp board; screenshots in the build PR

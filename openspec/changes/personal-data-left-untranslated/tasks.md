# Tasks: personal-data-left-untranslated

- [ ] **T01**: `src/site/components/NoTranslate.vue` (slot or `value` prop, renders `translate="no"`) (REQ-PDU-001)
- [ ] **T02**: Wrap the values listed in design.md "Where" in the named components (REQ-PDU-001)
- [ ] **T03**: `CaseField.vue` wraps values of format email, telephone, uri and fields marked `personal: true`; the contribution normaliser keeps `personal` on a field (REQ-PDU-001)
- [ ] **T04**: node test `tests/no-translate.spec.mjs` per design.md "Test"

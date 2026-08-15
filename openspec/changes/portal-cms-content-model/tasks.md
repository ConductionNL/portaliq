# Tasks: portal-cms-content-model

> Schema-only change (ADR-032 `kind: schema`). Five schemas plus one repair
> step. Checkbox budget: 4 tasks × 2 = 8 unindented `- [ ]` lines (cap 20).

## Implementation Tasks

### Task 1: Declare the website schema
- **spec_ref**: `openspec/changes/portal-cms-content-model/specs/cms-content-model/spec.md#requirement-the-register-must-declare-a-website-as-the-scope-for-all-content`
- **files**: `lib/Settings/cms_register.json`, `tests/Unit/Settings/CmsRegisterTest.php`
- **acceptance_criteria**:
  - `website` declares name, logo, theme reference, `domains[]` with hostname + verification state, authentication configuration, locales and status
  - `menu`, `page`, `glossaryTerm`, `media` each carry a REQUIRED `website` reference; a write without it is rejected by validation, not defaulted
  - Property titles and descriptions satisfy ADR-011 and the schema-property-titles gate
- [ ] Implement
- [ ] Test

### Task 2: Declare menu, page and media, preserving the deployed shapes
- **spec_ref**: `openspec/changes/portal-cms-content-model/specs/cms-content-model/spec.md#requirement-the-menu-shape-must-be-preserved-exactly-as-deployed`
- **files**: `lib/Settings/cms_register.json`, `tests/Unit/Settings/CmsRegisterTest.php`
- **acceptance_criteria**:
  - A menu object exported from a live OpenCatalogi deployment validates unchanged — no property renamed, dropped or defaulted; the test fixture is a real exported object, not one written to match the schema
  - Nesting beyond two levels is rejected
  - `page.body` is a closed `{ type: "grid" | "markdown" }`; a `grid` body `$ref`s the canonical manifest-v2 `$defs.widgetEntry` rather than restating it, so the two cannot drift
  - A `markdown` body round-trips byte-identical
- [ ] Implement
- [ ] Test

### Task 3: Declare the glossary term
- **spec_ref**: `openspec/changes/portal-cms-content-model/specs/cms-content-model/spec.md#requirement-the-register-must-declare-a-glossary-term`
- **files**: `lib/Settings/cms_register.json`, `tests/Unit/Settings/CmsRegisterTest.php`
- **acceptance_criteria**:
  - `glossaryTerm` carries term, definition, synonyms, source reference and relations to other terms
  - Relations use OpenRegister's own relation handling (the relation-dialect gate passes), not string references
  - A relation to a term on a different website is rejected
- [ ] Implement
- [ ] Test

### Task 4: Back-fill the website reference on existing content
- **spec_ref**: `openspec/changes/portal-cms-content-model/specs/cms-content-model/spec.md#requirement-existing-content-must-be-adopted-without-loss`
- **files**: `lib/Repair/BackfillCmsWebsiteReference.php`, `tests/Unit/Repair/BackfillCmsWebsiteReferenceTest.php`
- **acceptance_criteria**:
  - Assigns a website to `menu` / `page` / glossary objects that have none; idempotent — a second run reports zero changed and writes nothing
  - REPORTS the count of objects changed, so a no-op run is distinguishable from a run that never executed (the audit-purge lesson: a job that had never run looked exactly like a job with nothing to do)
  - Registered as a repair step, NOT in `<install>` — a data migration must never sit on the unconditional install hook
  - After completion, a query for content objects with no website returns empty; this is asserted, not assumed
- [ ] Implement
- [ ] Test

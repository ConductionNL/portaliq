# Tasks: portal-cms-admin-ui

> Editorial surface + publish-time validation (ADR-032 `kind: code`).
> BLOCKS `opencatalogi/cms-handover`. Checkbox budget: 3 tasks × 2 = 6
> unindented `- [ ]` lines (cap 20).

## Implementation Tasks

### Task 1: Admin surfaces for the four content types
- **spec_ref**: `openspec/changes/portal-cms-admin-ui/specs/portaliq-cms/spec.md#requirement-an-editor-must-be-able-to-manage-content-without-the-api`
- **files**: `src/manifest.d/cms.json`, `src/views/cms/`, `src/registry.js`
- **acceptance_criteria**:
  - `website`, `menu`, `page`, `glossaryTerm` each have index and detail surfaces built on the SHARED manifest components — no parallel object store, no hand-rolled table
  - An administrator can create a site, menu, markdown page and glossary term and publish them, with no API client, and the public site then renders
  - The menu editor enforces the two-level limit the schema declares
- [ ] Implement
- [ ] Test

### Task 2: Publish-time validation
- **spec_ref**: `openspec/changes/portal-cms-admin-ui/specs/portaliq-cms/spec.md#requirement-publishing-must-refuse-content-that-would-render-broken`
- **files**: `lib/Service/CmsPublishValidator.php`, `tests/Unit/Service/CmsPublishValidatorTest.php`
- **acceptance_criteria**:
  - A menu item linking to a route with NO page is refused, naming the route — the rig's `/begrippen` defect, whose only symptom was a console 404 on a page that looked fine
  - A menu item pointing at a DRAFTED page WARNS rather than blocks; refusing it would make the guard the obstacle in a normal editorial workflow
  - A website with no published page at `/` is refused — `open-venray` currently 404s on its own front door
  - Duplicate routes within one website are refused
- [ ] Implement
- [ ] Test

### Task 3: Domain verification trigger
- **spec_ref**: `openspec/changes/portal-cms-admin-ui/specs/portaliq-cms/spec.md#requirement-domain-verification-must-be-triggerable-from-the-ui`
- **files**: `src/views/cms/WebsiteDomains.vue`, `tests/e2e/cms-admin.spec.ts`
- **acceptance_criteria**:
  - The pending domain's exact TXT record name and value are shown and copyable
  - Verification can be run and RE-run without re-adding the domain, because "DNS has not propagated yet" is the normal first outcome, not an error state
  - The e2e covers the whole editorial path: create a site, add a domain, see the record, publish a page, load the public site
- [ ] Implement
- [ ] Test

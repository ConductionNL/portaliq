# Tasks: docusaurus-plugin-portaliq

> New project; Portaliq owns the contract (ADR-032 `kind: code`).
> Checkbox budget: 3 tasks × 2 = 6 unindented `- [ ]` lines (cap 20).

## Implementation Tasks

### Task 1: Build a site from the public API
- **spec_ref**: `openspec/changes/docusaurus-plugin-portaliq/specs/docusaurus-plugin-portaliq/spec.md#requirement-the-plugin-must-build-a-site-from-the-public-api-alone`
- **files**: `src/index.js`, `src/contentClient.js`, `src/__tests__/build.spec.js` (new repository)
- **acceptance_criteria**:
  - Menu → sidebar, markdown pages → docs, glossary → a glossary route, for a configured website
  - Every outbound request is a public content API call, asserted by intercepting them — the plugin's architectural value is that it CANNOT reach an internal
  - Markdown reaches Docusaurus's own pipeline unconverted; a code fence and a table survive
  - Two-level menu nesting maps to Docusaurus sidebar categories
- [ ] Implement
- [ ] Test

### Task 2: Fail loudly, never publish incomplete
- **spec_ref**: `openspec/changes/docusaurus-plugin-portaliq/specs/docusaurus-plugin-portaliq/spec.md#requirement-a-build-must-fail-loudly-rather-than-publish-incomplete-content`
- **files**: `src/contentClient.js`, `src/__tests__/failure.spec.js`
- **acceptance_criteria**:
  - An unreachable API fails the build, naming the endpoint
  - A response below the configured expected minimum fails the build, stating BOTH counts — silent content loss is the failure mode that looks identical to success
  - A cached snapshot is used only when explicitly enabled; it is never a silent fallback
  - The zero-items case is covered explicitly, because an empty successful response is the shape most easily mistaken for "nothing to publish"
- [ ] Implement
- [ ] Test

### Task 3: Credential hygiene and gap reporting
- **spec_ref**: `openspec/changes/docusaurus-plugin-portaliq/specs/docusaurus-plugin-portaliq/spec.md#requirement-credentials-must-not-reach-build-output`
- **files**: `src/contentClient.js`, `src/__tests__/credentials.spec.js`, `README.md`
- **acceptance_criteria**:
  - No part of a configured token appears in the output directory or in build logs — asserted by searching the built output, and on a deliberately FAILED request, since that is the path that serialises context
  - A capability the built-in portal renders but the API cannot supply is reported as an API gap, naming it, rather than worked around
  - The README states the conformance obligation: a workaround here ends the headless property quietly
- [ ] Implement
- [ ] Test

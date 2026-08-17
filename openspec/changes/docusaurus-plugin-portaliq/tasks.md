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
- [x] Implement — `apps-extra/docusaurus-plugin-portaliq` (local repo; publishing it is an outward-facing step left to a human)
- [x] Test — 17 cases on `node --test`, no runner dependency. Verified against a live portal: 3 menus, 4 pages, 2 glossary terms, 11 sidebar entries.

### Task 2: Fail loudly, never publish incomplete
- **spec_ref**: `openspec/changes/docusaurus-plugin-portaliq/specs/docusaurus-plugin-portaliq/spec.md#requirement-a-build-must-fail-loudly-rather-than-publish-incomplete-content`
- **files**: `src/contentClient.js`, `src/__tests__/failure.spec.js`
- **acceptance_criteria**:
  - An unreachable API fails the build, naming the endpoint
  - A response below the configured expected minimum fails the build, stating BOTH counts — silent content loss is the failure mode that looks identical to success
  - A cached snapshot is used only when explicitly enabled; it is never a silent fallback
  - The zero-items case is covered explicitly, because an empty successful response is the shape most easily mistaken for "nothing to publish"
- [x] Implement
- [x] Test — including that a snapshot PRESENT but not enabled still fails, which is the case a boolean check gets wrong.

### Task 3: Credential hygiene and gap reporting
- **spec_ref**: `openspec/changes/docusaurus-plugin-portaliq/specs/docusaurus-plugin-portaliq/spec.md#requirement-credentials-must-not-reach-build-output`
- **files**: `src/contentClient.js`, `src/__tests__/credentials.spec.js`, `README.md`
- **acceptance_criteria**:
  - No part of a configured token appears in the output directory or in build logs — asserted by searching the built output, and on a deliberately FAILED request, since that is the path that serialises context
  - A capability the built-in portal renders but the API cannot supply is reported as an API gap, naming it, rather than worked around
  - The README states the conformance obligation: a workaround here ends the headless property quietly
- [x] Implement — and the guard is enforced, not just documented: `ContentClient.get()` refuses any request whose RESOLVED pathname leaves `/api/content/`. The first version checked the string before `new URL()` normalised it, so `../../../admin/settings` got through; a test now asks for exactly that.
- [x] Test

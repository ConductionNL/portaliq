# Tasks: portal-shared-runtime

> Replace the React portal with the shared runtime (ADR-032 `kind: code`).
> Checkbox budget: 5 tasks × 2 = 10 unindented `- [ ]` lines (cap 20).

## Implementation Tasks

### Task 1: Boot the shared runtime at the public origin
- **spec_ref**: `openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react`
- **files**: `src/portal/main.js`, `webpack.config.js`, `templates/portal.php`, `package.json`
- **acceptance_criteria**:
  - Boots via `bootstrapCnApp({ host: 'public' })` and renders with no Nextcloud globals present
  - `webpack.portal.js` is deleted and `webpack.config.js` no longer needs `output.clean = { keep: … }` — the hazard it guarded (an admin-only rebuild silently deleting the portal bundle, leaving a bare `<div>` and a 404 with NO console error) cannot recur once there is one config
  - Verified against a real second build: run the admin build alone and confirm the portal still serves
- [ ] Implement
- [ ] Test

### Task 2: Portal manifest becomes manifest v2
- **spec_ref**: `openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-a-portal-page-must-be-a-manifest-v2-page`
- **files**: `lib/Service/PortalManifestNormaliser.php`, `lib/Service/PortalContributionRegistry.php`, `tests/Unit/Service/PortalManifestNormaliserTest.php`
- **acceptance_criteria**:
  - Normalisation produces manifest-v2 pages that pass `validateManifestV2()`
  - procest / pipelinq / shillinq contributions render through `CnPageRenderer` with NO provider signature changed (ADR-046 intact) — asserted against their real providers, not a fixture written to fit
  - A markdown page renders through the existing markdown path, not a portal-local renderer
- [ ] Implement
- [ ] Test

### Task 3: Grid and the communal widget catalog
- **spec_ref**: `openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-portal-pages-must-use-the-manifest-grid-unchanged`
- **files**: `src/portal/widgets.js`, `tests/Unit/portal/widgetCatalog.spec.js`
- **acceptance_criteria**:
  - Widget placement uses `$defs.widgetEntry` on any page type; an OpenBuild-authored page occupies the same cells here
  - Overflow fails with the EXISTING canonical message, not a portal-local variant
  - The catalog is read from `dashboardWidgetRegistry` filtered to `public: true`; a newly registered public widget appears with no Portaliq change — asserted by adding one in the test
  - A non-public widget renders inert and its code does not execute
- [ ] Implement
- [ ] Test

### Task 4: In-page journeys
- **spec_ref**: `openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-journeys-must-mount-in-page`
- **files**: `src/portal/pages/JourneyPage.vue`, `tests/e2e/portal-journey.spec.ts`
- **acceptance_criteria**:
  - An anonymous journey completes end to end at the public origin, writing the declared objects with NO ownership stamped
  - Step sequence, validation and submit behaviour are identical to the same journey in a nc-vue modal — compared, not assumed
  - File upload and nested-array answers work, since `/register` needs both
- [ ] Implement
- [ ] Test

### Task 5: Budget and measured parity
- **spec_ref**: `openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-parity-with-the-react-portal-must-be-measured-not-asserted`
- **files**: `.github/workflows/portal-budget.yml`, `docs/portal-parity.md`
- **acceptance_criteria**:
  - The first-load budget FAILS the build when exceeded, measured on bytes TRANSFERRED for a first visit with an empty cache — not the build's own emitted-size report
  - Widget code and `CnJourney` are route-split; a page rendering neither does not transfer them
  - Every surface the React portal served is compared against its replacement on rendered content AND issued requests, and the result is recorded — before the React source is deleted, not after
- [ ] Implement
- [ ] Test

# Tasks: retire-the-vendored-woo-bundle

> Content plus removal (ADR-032 `kind: mixed`). Every deletion is last, because
> the bundle is serving a live municipality today.
> Checkbox budget: 6 tasks × 2 = 12 unindented `- [ ]` lines (cap 20).

## Implementation Tasks

### Task 1: Point the publication blocks at the real WOO register
- **spec_ref**: `openspec/changes/retire-the-vendored-woo-bundle/specs/portaliq-cms/spec.md#requirement-the-publication-blocks-must-serve-a-real-publication-register`
- **files**: `src/site/lib/publicationDetail.js`, `src/site/lib/federatedSearch.js`, `src/site/components/PublicationDetailBlock.vue`, `src/site/components/FederatedSearchBlock.vue`
- **acceptance_criteria**:
  - `federatedSearch` returns results from the WOO publication register, not a demo row, with facets and paging asserted against a realistic row count rather than a handful of fixtures
  - `publicationDetail` renders a real publication including its attachments, and a publication that does not exist 404s without revealing whether the id was ever valid
  - Measured, not assumed: record the row count the search was exercised against in the test, so a later reader can tell whether "it works" meant ten rows or ten thousand
- [ ] Implement
- [ ] Test

### Task 2: Author the Tilburg portal as content
- **spec_ref**: `openspec/changes/retire-the-vendored-woo-bundle/specs/portaliq-cms/spec.md#requirement-the-woo-public-surface-must-be-authored-as-portal-content`
- **files**: `lib/Settings/portaliq_register.json`, `lib/Service/DemoDataService.php`
- **acceptance_criteria**:
  - One `portal` row carries the domain, theme, locales and publication status; the home, themes, search and publication pages exist as `page` rows with grid bodies built only from the public block vocabulary
  - The navigation is a `menu` row, so an editor changes it without a deploy
  - No block on any of these pages is one this repo would have to invent: every widget key used is already in the public catalogue
- [ ] Implement
- [ ] Test

### Task 3: Route the authenticated surface at the capabilities that own it
- **spec_ref**: `openspec/changes/retire-the-vendored-woo-bundle/specs/portaliq-cms/spec.md#requirement-the-bundled-auth-and-forms-screens-must-not-be-reimplemented-as-content`
- **files**: `src/site/App.vue`, `src/site/components/SiteMenu.vue`
- **acceptance_criteria**:
  - Sign-in from the site enters the existing OIDC edge (DigiD, eHerkenning, eIDAS) rather than a bundled login screen, and the bundled `/login`, `/register`, `/reminder` and `/authorization` screens are reproduced nowhere
  - "Mijn omgeving" resolves to the authenticated portal and its contribution aggregate, not to a new page type
  - Each `/forms/*` route is expressed as a portal-contribution action, or is explicitly recorded as out of scope with a reason, so none is silently dropped
- [ ] Implement
- [ ] Test

### Task 4: Serve the authored portal on the WOO paths
- **spec_ref**: `openspec/changes/retire-the-vendored-woo-bundle/specs/portaliq-cms/spec.md#requirement-the-authored-portal-must-answer-on-the-paths-the-bundle-answered-on`
- **files**: `appinfo/routes.php`, `lib/Controller/PortalPageController.php`
- **acceptance_criteria**:
  - Every path the bundle served that carries a public surface resolves to the authored portal, or redirects to where that surface now lives
  - No path that used to render silently 404s: the set of previously-served public paths is enumerated in the test, not sampled
- [ ] Implement
- [ ] Test

### Task 5: Prove parity before anything is deleted
- **spec_ref**: `openspec/changes/retire-the-vendored-woo-bundle/specs/portaliq-cms/spec.md#requirement-parity-must-be-demonstrated-before-the-bundle-is-removed`
- **files**: `tests/e2e/woo-parity.spec.ts`
- **acceptance_criteria**:
  - An e2e walks the authored portal's home, themes, search and a publication detail, and asserts each renders its expected blocks with real content
  - The spec fails rather than skips when the portal content is absent, for the same reason the cross-app contribution spec does: a skip here would let the deletion proceed on silence
  - This task is the gate on Task 6; the deletion does not land while it is unticked
- [ ] Implement
- [ ] Test

### Task 6: Delete the bundle, the controller and the routes
- **spec_ref**: `openspec/changes/retire-the-vendored-woo-bundle/specs/portaliq-cms/spec.md#requirement-the-vendored-bundle-must-leave-the-repository`
- **files**: `woo/`, `lib/Controller/WooController.php`, `appinfo/routes.php`, `tests/Unit/Controller/WooControllerTest.php`
- **acceptance_criteria**:
  - `woo/` (28 MB), `WooController` and the `woo#serve` / `woo#servePath` routes are gone, and no test asserts the old surface: the retired-surface tests are inverted rather than deleted, so a silent return of the bundle is caught
  - Requires explicit sign-off from the municipality recorded on the PR, because restoring the directory means finding the build again, not reverting a commit
  - Repository size is measured before and after, so the claimed saving is a number rather than an adjective
- [ ] Implement
- [ ] Test

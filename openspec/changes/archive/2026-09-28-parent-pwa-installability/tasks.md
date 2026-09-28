# Tasks: parent-pwa-installability

## Implementation Tasks

### Task 1: The portal serves its own manifest, linked from its own page
- **spec_ref**: `openspec/changes/parent-pwa-installability/specs/parent-pwa-installability/spec.md#requirement-the-portal-serves-a-web-app-manifest-naming-the-resolved-portal`
- **files**: `lib/Controller/PortalManifestController.php`, `appinfo/routes.php`, `templates/portal.php`, `lib/Controller/PortalPageController.php`, `tests/Unit/Controller/PortalManifestControllerTest.php`
- **acceptance_criteria**:
  - GIVEN a portal loaded with `?org=gemeente-x` WHEN the manifest route is requested THEN its `name` names gemeente-x's resolved organisation and `start_url` carries `?org=gemeente-x`
  - GIVEN the portal shell page WHEN rendered THEN it links its own manifest via `Util::addHeader()`
- [x] Implement
- [x] Test

### Task 2: The service worker caches the shell, never the API
- **spec_ref**: `openspec/changes/parent-pwa-installability/specs/parent-pwa-installability/spec.md#requirement-the-service-worker-caches-the-app-shell-and-never-the-api`
- **files**: `lib/Controller/PortalManifestController.php`, `appinfo/routes.php`, `src/portal/serviceWorker.js`, `src/portal/main.jsx`, `tests/Unit/Controller/PortalManifestControllerTest.php`
- **acceptance_criteria**:
  - GIVEN the service worker route WHEN requested THEN it answers `application/javascript` with `Service-Worker-Allowed` scoping it to the whole app path
  - GIVEN `serviceWorker.js`'s fetch handler WHEN a request path is checked THEN a `/portal/api/` path (or anything outside the shell asset allow-list) is forwarded to the network before any cache read/write is possible
  - GIVEN `main.jsx` boots in a browser without service worker support WHEN registration is attempted THEN it fails silently and the app still renders
- [x] Implement
- [x] Test

### Task 3: A dismissible install control
- **spec_ref**: `openspec/changes/parent-pwa-installability/specs/parent-pwa-installability/spec.md#requirement-an-installable-browser-offers-a-dismissible-install-control`
- **files**: `src/portal/App.jsx`
- **acceptance_criteria**:
  - GIVEN `beforeinstallprompt` fires WHEN the portal is showing THEN its own dismissible control appears and the browser's default mini-infobar is prevented
  - GIVEN the control is clicked WHEN handled THEN `.prompt()` is called on the stored event
  - GIVEN `appinstalled` fires, or the visitor dismisses the control WHEN either happens THEN the control disappears
- [x] Implement
- [x] Test (no automated browser test in this change — `beforeinstallprompt` cannot be triggered reliably by this apply pass's headless Chromium; verified by code review of the event wiring, per the spec's own `@e2e exclude` notes)

## Quality checklist

- All new backend logic covered by PHPUnit unit tests (`tests/Unit/`)
- No Newman contract exists for this app's public routes today, so none is added (matches this lane's earlier precedent)
- Frontend changes are small and reviewed by code inspection; no vitest/jest harness exists yet for `src/portal/`'s `.jsx` files (same gap noted in `guardian-self-service-profile`, this lane)
- All tests pass (`composer test`)
- Dutch strings for the install control follow the existing hardcoded-Dutch convention already used elsewhere in `src/portal/`
- `openspec validate parent-pwa-installability --strict` passes

## Skipped artifacts

- **discovery**: the mechanism (manifest + service worker + install prompt) is a well-established web platform pattern; no technical uncertainty about Nextcloud's own APIs after confirming `Util::addHeader()` and `RENDER_AS_BASE` this session.
- **contract**: single project, no cross-project consumer of either new route.
- **migration**: no schema change.
- **test-plan**: three tasks with clear GIVEN/WHEN/THEN acceptance criteria, each already marking which parts are browser-untestable in this apply pass and why.

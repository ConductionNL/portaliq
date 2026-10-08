# Tasks: site-shows-what-was-published

- [x] **T01**: `fetchPage(route, portal, { fresh })` reads with `cache: 'reload'` (REQ-SSP-001). Verification: `tests/site-edit-mode.spec.mjs`.
- [x] **T02**: The editor's `mount()` passes `onSaved`; the site re-reads the page after a publish and on leaving edit mode (REQ-SSP-001). Verification: `tests/site-edit-mode.spec.mjs`.
- [x] **T03**: `ContentController` answers a signed-in Nextcloud user `private, no-store` (REQ-SSP-001). Verification: PHPUnit `ContentControllerTest`.
- [x] **T04**: `openspec validate site-shows-what-was-published --strict`.

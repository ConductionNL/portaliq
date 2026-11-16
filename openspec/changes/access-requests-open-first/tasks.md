# Tasks: access-requests-open-first

### Task 1: Open access requests come first
- **spec_ref**: `openspec/changes/access-requests-open-first/specs/portal-identity/spec.md#requirement-open-access-requests-come-first`
- **files**: `lib/Settings/portaliq_register.json`, `lib/Service/Identity/PortalAccessRequestService.php`, `lib/Repair/BackfillAccessRequestStateRank.php`, `appinfo/info.xml`, `lib/Settings/example-sets/zuiddrecht.json`, `src/manifest.json`
- [x] Implement
- [x] Test: `tests/Unit/Service/Identity/PortalAccessRequestServiceTest.php`, `tests/Unit/Repair/BackfillAccessRequestStateRankTest.php`, `tests/access-requests-open-first.spec.mjs`

### Task 2: The requested access reads as the board draws it
- **spec_ref**: `openspec/changes/access-requests-open-first/specs/portal-identity/spec.md#requirement-the-requested-access-reads-as-the-board-draws-it`
- **files**: `src/manifest.json`
- [x] Implement: `overlineText` on the column
- [x] Test: `tests/access-requests-open-first.spec.mjs`

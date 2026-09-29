# Tasks: operate-show-per-case-type

## The choice

- [x] **T01**: `portal.hiddenCaseTypes` in `lib/Settings/portaliq_register.json`, register version bump; `lib/Service/CaseTypeVisibility.php::isHidden()` (REQ-OSC-001). Verification: `CaseTypeVisibilityTest`.

## Enforcement

- [x] **T02**: `PortalCaseListReader::listCases()` and `listMandatedCases()` drop hidden types (REQ-OSC-002). Verification: `PortalCaseListReaderTest::testHiddenCaseTypeIsNotListed`, `::testOtherPortalUnaffected`.
- [x] **T03**: `ContributionController::object()` and `collection()` for `cases` collections: single 404 and list drop (REQ-OSC-002). Verification: `ContributionControllerTest::testHiddenCaseTypeIs404`.
- [x] **T04**: `PortalFormBindingResolver::render()` resolves a hidden type's binding to no form with `hiddenCaseType`, and the catalogue omits it (REQ-OSC-002). Verification: `PortalFormBindingResolverTest::testHiddenCaseTypeResolvesToNoForm`.

## The screen

- [x] **T05**: `GET` and `PUT /api/portals/{slug}/case-types`, admin-only, with the union of bindings, declared `caseTypeSource` and already hidden types (REQ-OSC-001). Verification: `PortalCaseTypesControllerTest::testNonAdminIsRefused`, `::testHiddenTypeStaysListed`.
- [x] **T06**: `PortalCaseTypes` custom page and the link from `PortalDetail` in `src/manifest.json`, with the switch and the warning (REQ-OSC-001, REQ-OSC-003). Verification: `tests/e2e/operate-show-per-case-type.spec.ts` hides and shows a type and checks "My cases" each time.

## Docs, strings and validation

- [x] **T07**: English and Dutch strings ("Case types", "Show in this portal", "Residents with a case of this type will no longer see it here."); a docs page for administrators; a note for case app authors on `caseTypeSource`. Verification: `npm run lint`, `test:l10n`.
- [x] **T08**: `openspec validate operate-show-per-case-type --strict`.

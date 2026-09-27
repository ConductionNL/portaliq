# Tasks: case-actions-sign-a-document

## Before any code

- [ ] **T01**: List every app that verifies the `X-Portal-Subject` assertion (grep `X-Portal-Subject` and `use=assertion` in the fleet on `development`) and record how each treats an unknown claim; stop if any rejects one (REQ-SGN-003). Verification: the list in the PR body.

## The manifest

- [ ] **T02**: `CollectionConfigNormaliser::resolveRowActions()` resolves endpoint actions by id, reduces object entries to their id, drops an endpoint row action without `rowField`, and marks each resolved entry with kind `update` or `endpoint` (REQ-SGN-001). Verification: `PortalManifestNormaliserTest::testEndpointRowActionResolvesById`, `::testInlineRowActionEndpointIsIgnored`, `::testEndpointRowActionWithoutRowFieldIsDropped`.
- [ ] **T03**: Drop a declared `scopeClaim` whose name equals a reserved assertion claim (REQ-SGN-003). Verification: `PortalManifestNormaliserTest::testReservedScopeClaimNameIsDropped`.

## The forward

- [ ] **T04**: `ContributionController::rowAction()` and the route `POST /portal/api/collections/{register}/{schema}/{id}/actions/{actionId}` in `appinfo/routes.php`, before the catch-all: 401, 403, scoped read, 404, `rowField` stamp, forward, relay, audit (REQ-SGN-002). Verification: `ContributionControllerTest::testRowActionForeignRowIs404AndNotForwarded`, `::testRowActionStampsRowFieldOverClientValue`, `::testRowActionUnknownActionIs403`.
- [ ] **T05**: `PortalSessionService::issueAssertion()` and `PortalJwtService::createAssertion()` take an optional resolved scope claim and add it as one extra claim; `action()` and `rowAction()` pass it when the action declares `scopeClaim`, and refuse with 403 when it does not resolve (REQ-SGN-003). Verification: `PortalJwtServiceTest::testAssertionWithoutScopeClaimHasExactlyNineClaims`, `::testAssertionWithScopeClaimHasExactlyTenClaims`, `ContributionControllerTest::testUnresolvedScopeClaimStopsTheForward`.

## The screen

- [ ] **T06**: `PageView.jsx` passes endpoint row actions to `CollectionTable`; `App.jsx` gains `onEndpointRowAction` that calls the row-scoped forward and returns the relayed status and body (REQ-SGN-002). Verification: Vitest `PageView.test.jsx` renders a `sign` button on a row.
- [ ] **T07**: `src/portal/components/SigningDialog.jsx`: fetch through `viewDocument`, render the PDF and a download link, the confirmation checkbox, the disabled sign button, the success and refusal states (REQ-SGN-004). Verification: `tests/e2e/case-actions-sign-a-document.spec.ts` signs a seeded filinq request and sees "You signed {documentName}."
- [ ] **T08**: `src/portal/components/DeclineDialog.jsx`: reason field, forward, success and refusal states (REQ-SGN-005). Verification: the same Playwright spec declines a second seeded request.
- [ ] **T09**: `onAction` for page-level endpoint buttons shows the relayed result instead of discarding it. Verification: Vitest on `App.jsx` with a stubbed fetch returning 403.

## Docs, strings and validation

- [ ] **T10**: English strings in `src/portal/i18n/en.json` and Dutch in `nl.json` for "Sign", "Decline to sign", "I have read this document and I sign it.", "Why do you decline?", "You signed {documentName}.", "You declined to sign {documentName}.", "This document cannot be shown here."; a docs page for administrators on what the signing screen needs from a contribution. Verification: `npm run lint`, `test:l10n`.
- [ ] **T11**: `openspec validate case-actions-sign-a-document --strict`.

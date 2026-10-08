# Tasks: case-page-objection-and-complaint

Board: `Zaak` on the Zuiddrecht canvas (`5NkFW28vZUUij43xzxHg5a`). New frontend work lands in the Vue site `src/site/`, not in the React portal.

## Contract and server

- [ ] **T01**: `lib/Contribution/OnCaseConfigNormaliser.php`: keep `onCase` only when `crossRef` names a key of the action's `crossRefs`, `order` is an integer, `window.from` a field name and `window.days` a positive integer, `closedText` a string; drop the key, never the action (REQ-COC-001)
  - Unit `tests/Unit/Contribution/OnCaseConfigNormaliserTest.php`: well formed kept, unknown crossRef dropped with the action kept, `days: 0` dropped
- [ ] **T02**: Call the normaliser from `lib/Contribution/ActionConfigNormaliser.php` after the cross-ref normaliser; document `onCase` in `lib/Contribution/IPortalContributionProvider.php` (REQ-COC-001)
- [ ] **T03**: `lib/Service/CaseActionsResolver.php`: list the resident's create actions whose `onCase.crossRef` targets this case's collection, sorted by `order`, each `{app, action, label, open, closesOn?, closedText?}`; window from the day after `from` through `days` days, end of day Europe/Amsterdam, missing date is closed (REQ-COC-002, REQ-COC-003)
  - Unit `tests/Unit/Service/CaseActionsResolverTest.php`: open on day 1 and day 42, closed on day 43, closed without a date, empty for a contribution without `onCase`
- [ ] **T04**: `CitizenCaseController::show()` answers `caseActions` from the resolver (REQ-COC-002)
- [ ] **T05**: `ContributionController::create()` accepts `onCase=<register>/<schema>/<id>`: scoped re-read of the case, window re-check (409 `case_action_closed`), case id written into the crossRef field over the body, then `PortalCrossRefGuard` as before (REQ-COC-003, REQ-COC-004)
  - Unit `tests/Unit/Controller/ContributionControllerOnCaseTest.php`: forged body overwritten, foreign case 403, closed window 409, nothing stored on a refusal

## The case screen

- [ ] **T06**: `src/site/components/e/CitizenCase.vue`: a group with `role="group"` and `aria-label` "Meer acties voor deze zaak" holding the `caseActions` buttons before the change-proposal and withdraw controls; a closed action shows its sentence (REQ-COC-002)
- [ ] **T07**: `src/site/components/e/CaseActionForm.vue`: the action's fields minus the crossRef field, heading focused on open, cancel sends nothing, submit through `src/site/lib/` portal API with `onCase`; success notice with the reference and the Mijn zaken line; a 409 shows the server's sentence (REQ-COC-004, REQ-COC-005)
  - Playwright `tests/e2e/case-page-objection-and-complaint.spec.ts`: Bezwaar maken on a decided case, the bezwaar stored with the case id, the receipt shown; a case past 42 days shows the closed sentence
- [ ] **T08**: Update `tests/zuiddrecht-resident-boards.spec.mjs`: with `onCase` declared, Bezwaar maken and Klacht indienen are on the case page, not on the overview

## Strings, docs and the sibling

- [ ] **T09**: Dutch and English strings: the group name, the default closed sentence, the success notice per kind; `l10n/` updated
- [ ] **T10**: Docs page `docs/features/objection-and-complaint-on-the-case.md` with the `onCase` key and an example
- [ ] **T11**: Open the dossiq issue for its half: `onCase` on `createBezwaar` (window 42 days from the decision sent date) and `createKlacht`, and both removed from the overview page
- [ ] **T12**: `openspec validate case-page-objection-and-complaint --strict`

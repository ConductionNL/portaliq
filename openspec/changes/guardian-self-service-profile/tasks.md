# Tasks: guardian-self-service-profile

## Implementation Tasks

### Task 1: A proposer can list their own proposals
- **spec_ref**: `openspec/changes/guardian-self-service-profile/specs/change-proposal-queue/spec.md#requirement-a-proposer-can-list-their-own-proposals-req-cpq-005`
- **files**: `lib/Service/Proposals/ProposalService.php`, `lib/Service/Proposals/ProposalQueueReader.php`, `lib/Controller/ProposalController.php`, `appinfo/routes.php`, `tests/Unit/Service/Proposals/ProposalServiceTest.php`, `tests/Unit/Controller/ProposalControllerTest.php`
- **acceptance_criteria**:
  - GIVEN two proposals with different `proposedBy` WHEN `ProposalService::mine()` is called with one of them THEN only that one is returned, any state
  - GIVEN a valid bearer resolving to `subjectRef = guardian-1` WHEN `GET /portal/api/proposals/mine` is called THEN the query is filtered by `guardian-1`, never a client-supplied value
  - GIVEN no valid bearer WHEN `GET /portal/api/proposals/mine` is called THEN the response is 401 and no read is issued
- [ ] Implement
- [ ] Test

### Task 2: The portal SPA renders propose-change, submit and withdraw
- **spec_ref**: `openspec/changes/guardian-self-service-profile/specs/change-proposal-queue/spec.md#requirement-the-portal-spa-can-submit-and-withdraw-a-proposal-req-cpq-006`
- **files**: `src/portal/components/ProposeChangeForm.jsx`, `src/portal/components/PageView.jsx`, `src/portal/lib/portalApi.js`
- **acceptance_criteria**:
  - GIVEN a `propose-change` action's `proposable` list and a detail row WHEN the guardian edits one field and submits with a note THEN the request body carries only that field's `{property, proposedValue}` and the note
  - GIVEN a queued proposal the guardian made WHEN they open their own proposals list THEN it shows with a withdraw action, and withdrawing calls the existing withdraw route
  - GIVEN an accepted or rejected proposal WHEN shown in the list THEN no withdraw action is offered
- [ ] Implement
- [ ] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- New/changed API endpoint covered by the existing `ProposalControllerTest` suite where the harness allows it (see `portal-create-cross-refs`'s PR #607 note: `ContributionControllerTest`-style harness tests error with `Class "OCP\IRequest" does not exist` outside a Nextcloud tree on this fleet's clones — the same is expected for `ProposalControllerTest` here, and is inherited, not introduced)
- UI changes covered by a component-level test asserting the built request body (no live browser needed for this change; no Playwright suite exists yet under `tests/e2e/` for the portal SPA's React components)
- All tests pass (`composer test`)
- Dutch (`nl_NL`) strings for the new form and list follow the existing hardcoded-Dutch convention already used by `SchemaForm.jsx` (this app's portal SPA does not route these strings through a translation catalogue yet)
- `openspec validate guardian-self-service-profile --strict` passes

## Skipped artifacts

- **discovery**: the approach is clear and uses only already-shipped mechanisms (`change-proposal-queue`, `portal-create-cross-refs`); no technical uncertainty to research.
- **contract**: single project (portaliq only), no cross-project API — `/portal/api/proposals/mine` is consumed by portaliq's own SPA.
- **migration**: no schema change — `changeProposal` already carries `proposedBy`.
- **test-plan**: two tasks, each with clear GIVEN/WHEN/THEN acceptance criteria that already serve as the test plan.

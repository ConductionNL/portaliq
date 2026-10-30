# Tasks: change-proposals-reach-the-case-page

## Portaliq

- [ ] **T01**: `ProposalController::proposeAsColleague()`: drop the `proposable` parameter and resolve the allow-list from the contributions for that register and schema (reuse `proposableFor()`, joined across audiences); 403 `not_proposable` when none declares it (REQ-CPC-003). Verification: `ProposalControllerTest` refuses a request-supplied list and a record without a declaration.
- [ ] **T02**: `GET /apps/portaliq/api/proposals/mine` with `register`, `schema`, `id` in `appinfo/routes.php`, gated on `PortalCaseAccessGuard::mayRead`, filtered on `proposedBy` = the signed-in uid (REQ-CPC-002). Verification: `ProposalControllerTest` (own only, unreadable record 403).
- [ ] **T03**: `GET /api/proposals` (or a small sibling route) tells the widget whether the user may review and which properties are proposable with their current values, so the widget picks queue or form without a second guess on the client (REQ-CPC-001).
- [ ] **T04**: `src/integrations/ProposalQueueWidget.vue` and `proposalQueue.js`: the "Wijziging voorstellen" form and the own-proposals list with withdraw for a reader; the queue for a reviewer; strings in Dutch and English; NcSelect-free inputs labelled (REQ-CPC-001). Verification: node test `tests/proposal-queue-widget.spec.mjs` for the request body (only changed fields), the reader and reviewer views, and withdraw.
- [ ] **T05**: Update the `portaliq-change-proposal-queue` leaf descriptor if its props change, and keep gate-24 parity (descriptor and JS registration).

## Dossiq (built there, ConductionNL/dossiq#3215)

- [ ] **T06**: Dossiq case detail page config places `{type: integration, integrationId: portaliq-change-proposal-queue}`; its `mijnZaken` contribution declares a `propose-change` action with the proposable applicant contact fields. PR on dossiq `development`, linked here.

## Proof

- [ ] **T07**: Playwright `tests/e2e/change-proposals-reach-the-case-page.spec.ts`: a colleague proposes, a handler accepts, the case carries the value and its audit trail names the handler. Live check against PtVoorstelBeoordelen and ZaakWijzigingVoorstellen; screenshots in the build PR.
- [ ] **T08**: Move `act-propose-colleague`, `act-staff-review-queue` and `sib-dossiq-2-21` to built in `openspec/parity/capabilities.json` once T06 and T07 pass.
- [ ] **T09**: `openspec validate change-proposals-reach-the-case-page --strict`.

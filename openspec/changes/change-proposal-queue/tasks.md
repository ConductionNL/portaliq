# Tasks: change-proposal-queue

## Schema and service

- [ ] **T01**: Add `changeProposal` to `lib/Settings/portaliq_register.json` with the lifecycle `queued`, `accepted`, `rejected`, `withdrawn` (REQ-CPQ-001)
- [ ] **T02**: Add `ProposalService` with `propose`, `accept`, `reject`, `withdraw`; accept writes the subject as the reviewer through the objects API (REQ-CPQ-003)

## Ways in

- [ ] **T03**: Add the `propose-change` contribution action with a `proposable` property list in the contribution manifest; derive `proposedBy` from the session (REQ-CPQ-002)
- [ ] **T04**: Add `POST /apps/portaliq/api/proposals` for colleagues with read but not write on the subject (REQ-CPQ-002)

## Leaves

- [ ] **T05**: Register `portaliq-change-proposals` (data-provider, `list` and `create`) on `RegisterLeafProvidersEvent` (REQ-CPQ-004)
- [ ] **T06**: Register `portaliq-change-proposal-queue` (render-surface, widget and tab) in PHP and JS under one id (REQ-CPQ-004)

## Quality

- [ ] **T07**: PHPUnit: accept refused without write on the subject; drifted snapshot flagged; reject needs a reason
- [ ] **T08**: Playwright `tests/e2e/change-proposal-queue.spec.ts`: propose from the portal, accept in the widget, subject updated
- [ ] **T09**: Dutch and English strings; docs with screenshots; hand the leaf ids to dossiq

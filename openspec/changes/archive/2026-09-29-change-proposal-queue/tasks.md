# Tasks: change-proposal-queue

## Schema and service

- [x] **T01**: Add `changeProposal` to `lib/Settings/portaliq_register.json` with the lifecycle `queued`, `accepted`, `rejected`, `withdrawn` (REQ-CPQ-001)
- [x] **T02**: Add `ProposalService` with `propose`, `accept`, `reject`, `withdraw`; accept writes the subject as the reviewer through the objects API (REQ-CPQ-003)

## Ways in

- [x] **T03**: Add the `propose-change` contribution action with a `proposable` property list in the contribution manifest; derive `proposedBy` from the session (REQ-CPQ-002)
- [x] **T04**: Add `POST /apps/portaliq/api/proposals` for colleagues with read but not write on the subject (REQ-CPQ-002)

## Leaves

- [x] **T05**: Register `portaliq-change-proposals` (data-provider, `list` and `create`) on `RegisterLeafProvidersEvent` (REQ-CPQ-004)
- [x] **T06**: Register `portaliq-change-proposal-queue` (render-surface, widget and tab) in PHP and JS under one id (REQ-CPQ-004)

## Quality

- [x] **T07**: PHPUnit: accept refused without write on the subject; drifted snapshot flagged; reject needs a reason
- [x] **T08**: Playwright `tests/e2e/change-proposal-queue.spec.ts`: propose from the portal, accept in the widget, subject updated
- [x] **T09**: Dutch and English strings; docs with screenshots; hand the leaf ids to dossiq

## What shipped

Shipped and covered: the `changeProposal` schema with its four-state lifecycle
and the snapshot on every change, `ProposalService` (`propose`, `accept`,
`reject`, `withdraw`) with the drift check and the write-then-close order,
`ReviewerObjectWriter` (the one write portaliq makes with RBAC and multitenancy
ON, so the record's audit trail names the reviewer), the `propose-change`
contribution action with its `proposable` allow-list, and both ways in:
`POST /portal/api/proposals` for a portal subject and
`POST /apps/portaliq/api/proposals` for a colleague, who now needs read on the
record (`PortalCaseAccessGuard::mayRead`).

The leaves (T05, T06, 2026-09-29):

- `lib/Listener/RegisterProposalLeavesListener.php` contributes both on
  `RegisterLeafProvidersEvent`, registered by string in `Application`.
- `portaliq-change-proposals` is served by
  `lib/Service/Proposals/ChangeProposalsProvider.php` (storage `app-local`):
  `list` answers only a reviewer, `create` records a colleague's proposal as
  that colleague, `get`/`update`/`delete` are not offered.
- `portaliq-change-proposal-queue` is `src/integrations/ProposalQueueWidget.vue`
  over `src/integrations/proposalQueue.js`, registered by
  `src/integrations/registerProposalQueueLeaf.js` in mount mode, from
  `src/main.js` and from the new `portaliq-leaves` bundle (`src/leaves.js`,
  kept out of the shared chunks, because nothing loads those on another
  app's page).
- `scripts/check-integration-parity.sh` gives gate-24 its checker (vendored from
  filinq; R2 now applies to render-surface leaves only, as its header says).
- Docs: `docs/operations/reviewing-change-proposals.md` (no screenshots: no
  app places the widget yet). Leaf ids handed to dossiq in
  ConductionNL/dossiq#3215.

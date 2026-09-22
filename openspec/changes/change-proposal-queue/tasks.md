# Tasks: change-proposal-queue

## Schema and service

- [x] **T01**: Add `changeProposal` to `lib/Settings/portaliq_register.json` with the lifecycle `queued`, `accepted`, `rejected`, `withdrawn` (REQ-CPQ-001)
- [x] **T02**: Add `ProposalService` with `propose`, `accept`, `reject`, `withdraw`; accept writes the subject as the reviewer through the objects API (REQ-CPQ-003)

## Ways in

- [x] **T03**: Add the `propose-change` contribution action with a `proposable` property list in the contribution manifest; derive `proposedBy` from the session (REQ-CPQ-002)
- [x] **T04**: Add `POST /apps/portaliq/api/proposals` for colleagues with read but not write on the subject (REQ-CPQ-002)

## Leaves

- [ ] **T05**: Register `portaliq-change-proposals` (data-provider, `list` and `create`) on `RegisterLeafProvidersEvent` (REQ-CPQ-004)
- [ ] **T06**: Register `portaliq-change-proposal-queue` (render-surface, widget and tab) in PHP and JS under one id (REQ-CPQ-004)

## Quality

- [x] **T07**: PHPUnit: accept refused without write on the subject; drifted snapshot flagged; reject needs a reason
- [x] **T08**: Playwright `tests/e2e/change-proposal-queue.spec.ts`: propose from the portal, accept in the widget, subject updated
- [ ] **T09**: Dutch and English strings; docs with screenshots; hand the leaf ids to dossiq

## What shipped, and what the leaves still need

Shipped and covered: the `changeProposal` schema with its four-state lifecycle
and the snapshot on every change, `ProposalService` (`propose`, `accept`,
`reject`, `withdraw`) with the drift check and the write-then-close order,
`ReviewerObjectWriter` (the one write portaliq makes with RBAC and multitenancy
ON, so the record's audit trail names the reviewer), the `propose-change`
contribution action with its `proposable` allow-list, and both ways in:
`POST /portal/api/proposals` for a portal subject and
`POST /apps/portaliq/api/proposals` for a colleague. Reviewing is gated by the
ADR-023 action `portal.review-proposal` plus a read of the record with RBAC on.

Left open, and marked so: **T05** and **T06**, the two leaves
(`portaliq-change-proposals` as a data-provider and
`portaliq-change-proposal-queue` as a render-surface). Portaliq consumes no
`RegisterLeafProvidersEvent` yet; that groundwork belongs to the
`leaf-integrations` change, and registering half a pair would fail gate-24
rather than help. The queue's data is already reachable over
`GET /apps/portaliq/api/proposals`, which is what both leaves will read.

For dossiq: the leaf ids above, the action type `propose-change` with its
`proposable` list, and the staff endpoints for accept and reject.


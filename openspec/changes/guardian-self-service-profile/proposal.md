---
kind: code
---

# Proposal: guardian-self-service-profile

Learniq round 1 competitor sweep, finding 9.11 "Parents maintain their own
contact data and preferences" (`learniq-round1/compare/findings.md` and
`change-plan.md` in ConductionNL/market-intelligence, 2026-09-25). moodle
lets a parent edit their own profile and notification preferences; ParnasSys'
Parro portal lets a parent propose NAW, medical data and noodnummers changes
that the school approves; rosariosis has a "My Preferences" self-edit item.
portaliq today has zero hits for parent self-maintained contact data.

## Summary

A portal subject (a guardian, a supplier, any audience) can already propose a
field change on a record they may read but not write — `change-proposal-queue`
(merged PR #604) shipped the schema, the service and both write routes. What
is missing is the read side and the frontend: nobody can list their own queued
proposals, and the portal SPA has no component that renders a `propose-change`
action at all. This change closes both gaps, so a guardian can open their own
profile, propose a correction with a note, see it queued, and withdraw it —
finding 9.11's whole loop — using only capability the fleet already shipped.

## Motivation

`change-plan.md`'s portaliq row for this finding says "written as proposals
the school can approve — same cross-ref risk as the create-action guard",
naming `portal-writer-crossref-guard` as the dependency. Both named
dependencies are already on `development`:

- `portal-writer-crossref-guard` (scholiq#43's root cause) shipped as the
  generic `crossRefs` declaration + `PortalCrossRefGuard`
  (`openspec/changes/portal-create-cross-refs`, PR #607, merged 2026-09-18).
- The write mechanism this row actually needs is `change-proposal-queue`
  (PR #604, merged 2026-09-18): `ProposalController::proposeFromPortal()`
  already re-verifies the subject owns the record through
  `PortalObjectReader::readObject()` — the SAME scoped-read primitive
  `PortalCrossRefGuard::ownedBySubject()` calls — before a proposal is even
  queued, so the "cross-ref risk" the finding flags is already closed by the
  existing write path. No new write-side code is needed or proposed here.

What is genuinely missing, found by reading `src/portal/` and
`lib/Controller/ProposalController.php` this session:

- `ProposalController::index()` is gated by `PortalCaseAccessGuard`
  (a reviewer's permission), so the proposer themselves has no route to list
  their own queued/accepted/rejected proposals.
- `PageView.jsx`'s action switch renders `create`/`update`/endpoint actions
  only; there is no component anywhere in `src/portal/` that submits a
  `changes` array with a note, or calls the existing withdraw route.

Without these two pieces, `change-proposal-queue`'s portal-facing half is
unreachable from the UI a guardian actually uses, so 9.11 stays unmet even
though the backend that would serve it already shipped.

## Affected Projects

- [x] Project: `portaliq` — read endpoint + portal SPA rendering for the
  guardian's own proposals; no other project's code changes.

## Scope

### In Scope

- `ProposalService::mine(string $proposedBy): array` — every proposal (any
  state) whose `proposedBy` equals the given reference.
- `ProposalController::mine()` — `GET /portal/api/proposals/mine`, bearer-
  gated through `PortalSessionService`, filtered server-side by the resolved
  subject's own `subjectRef`. The client never supplies whose proposals to
  list.
- `src/portal/components/ProposeChangeForm.jsx` — renders a `propose-change`
  action's `proposable` field allow-list, pre-filled from the current row,
  with a note field; submits only the fields whose value actually changed.
- `portalApi.js`: `proposeChange(action, id, changes, note)`,
  `withdrawProposal(id)`, `fetchMyProposals()`.
- Wiring `propose-change` into `PageView.jsx`'s `detail` block (computing
  `rowActions` for a detail block the same way the table block already does)
  and a new `onProposeChange` handler in `App.jsx`.
- A "my proposals" status list in the portal shell showing queued / accepted
  / rejected proposals with a withdraw button on queued ones.

### Out of Scope

- `change-proposal-queue`'s T05/T06 leaves (`portaliq-change-proposals`
  data-provider, `portaliq-change-proposal-queue` render-surface widget).
  Both stay blocked on `RegisterLeafProvidersEvent`, which `leaf-integrations`
  has not landed yet (confirmed zero hits in `lib/` this session) — this
  change does not touch that mechanism.
- Any app declaring a `propose-change` action on its own contribution (e.g.
  learniq naming which guardian-profile fields are proposable). That is
  domain-app work tracked separately (`portal-contribution-guardian-audiences`
  on the learniq side, per decision D1) and is not portaliq's to build.
- The staff review UI beyond what already exists
  (`POST /apps/portaliq/api/proposals/{id}/accept|reject`). A dedicated
  reviewer widget is exactly the blocked T06 leaf above.
- Re-implementing `portal-writer-crossref-guard` or `change-proposal-queue`'s
  core mechanism — both are done, cited above, and untouched by this change.

## Approach

Add one read method + one bearer-gated route on the existing
`ProposalService`/`ProposalController` pair, filtered by the subject's own
`subjectRef` exactly the way every other portal-facing read in this codebase
is scoped. On the frontend, add the missing action-type branch and API calls
following the existing `ActionFieldsForm`/`SchemaForm` conventions, and a
small status list component. Full technical detail in `design.md`.

## New Dependencies

None.

## Impact

- `lib/Service/Proposals/ProposalService.php` (new method)
- `lib/Controller/ProposalController.php` (new method)
- `appinfo/routes.php` (one new route)
- `src/portal/components/ProposeChangeForm.jsx` (new)
- `src/portal/components/PageView.jsx` (detail-block rowActions)
- `src/portal/lib/portalApi.js` (three new methods)
- `src/portal/App.jsx` (onProposeChange handler, my-proposals state)
- Unit tests for the new service/controller method; no schema/migration
  changes (the `changeProposal` schema already carries `proposedBy`).

## Cross-Project Dependencies

None beyond what already shipped (`portal-create-cross-refs`,
`change-proposal-queue`), both already on `development`.

## Risks

### Risk 1: A guardian queues a proposal and never learns its outcome
**Severity:** Low — **Mitigation:** the "my proposals" list added here shows
current state on demand; a push/inbox notification on decision is a natural
follow-up once `portal-notifications-dispatch`-style delivery exists for this
schema, tracked separately, not blocking this change.

### Risk 2: `mine()` becomes an unscoped read if `proposedBy` is ever taken
from client input instead of the resolved bearer
**Severity:** Medium — **Mitigation:** the controller method resolves
`proposedBy` exclusively from `PortalSessionService::resolveFromBearer()`,
the same call every other portal-facing route in this controller already
uses; a unit test pins that the query filter is the session's own
`subjectRef` and not a request parameter.

## Rollback Strategy

Revert the single commit. The new route and method are additive: no existing
route, schema or behaviour changes, so a revert has no data-migration
concern.

## Open Questions

None — scope resolved against the corpus and the current codebase this
session; see Motivation for what was found already shipped.

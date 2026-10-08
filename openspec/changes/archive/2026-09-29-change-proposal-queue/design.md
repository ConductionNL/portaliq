# Design: change-proposal-queue

Kind: code. One schema, one contribution action, two leaves.

## 1. `changeProposal` schema

In `lib/Settings/portaliq_register.json`, Schema.org `ProposeAction`.

| property | notes |
|---|---|
| `subject` | `{register, schema, id}` the record the change targets |
| `proposedBy` | `{kind: portal|user, ref}`: a portal subject assertion or a Nextcloud user id |
| `channel` | `portal` or `colleague` |
| `changes[]` | `{property, currentValue, proposedValue}` |
| `note` | the proposer's reason, optional |
| `status` | lifecycle `queued`, `accepted`, `rejected`, `withdrawn` |
| `decidedBy`, `decidedAt`, `decisionReason` | set on accept or reject |

`currentValue` is a snapshot at proposal time so a reviewer sees drift.

## 2. Two ways in

- Portal: a contribution action `propose-change` on a scoped object
  (`portal-contribution-contract`, endpoint actions). The server derives
  `proposedBy` from the portal session, never from the body. Only properties
  the contribution's field projection lists as `proposable` may be named.
- Internal: `POST /apps/portaliq/api/proposals` for a logged-in user with
  read on the subject. A user with write rights is told to edit directly.

## 3. Leaves (ADR-066)

- `portaliq-change-proposals`, kind `data-provider`, storage `app-local`:
  `list(register, schema, objectId)` returns queued proposals for that
  object; `create` appends a proposal for the calling user (the internal
  way in, as an append). No verb.
- `portaliq-change-proposal-queue`, kind `render-surface`, `renderMode:
  component`, `widget` and `tab` under one id. The widget shows the queue
  with diffs. Accept and reject run in portaliq's bundle and controller.

## 4. Accept and reject

`ProposalService::accept(id, reviewer, reason)`:
1. checks the reviewer has write on the subject through OpenRegister RBAC
2. writes the proposed values to the subject with the objects API as the
   reviewer, one update, so the owning app's audit trail names the reviewer
3. transitions the proposal to `accepted`

If the subject's current value moved since the snapshot, the widget shows
both and asks the reviewer to confirm. `reject` needs a reason and touches
only the proposal. The owning app is never called; it sees an object update
it already handles.

## 5. Risks

- A `proposable` list that is too wide lets a citizen propose a change to a
  status field. The default is empty; the contribution names each property.
- Portal subjects lose the queue when the proposal is withdrawn by them; the
  object keeps an `withdrawn` row for the audit trail.

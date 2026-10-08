---
kind: code
---

# Proposal: change-proposal-queue

## Summary

A citizen in the portal, or a colleague without write rights, proposes a
change to a field on a record. The proposal waits in a queue on that record.
Someone with write rights accepts or rejects it, with a reason. Portaliq owns
the queue; the owning app places it as a leaf (ADR-066) and never grows a
queue of its own.

## Why

Zaaksysteem queues field changes from colleagues and from the citizen portal
on the case file until the handler accepts them
(`zs/case-type-editor-anatomy.md`, Zaakdossier, in the dossiq competitor
analysis under `concurrentie-analyse/procest/_round2/`). dossiq has only a
`portaalVerzoek` schema and no queue (finding B24, M1 2.21). The portal side
of dossiq moves to portaliq (`move-portals-to-portaliq`), so the queue is
portaliq's and dossiq only accepts or rejects. Decision D11 asks portaliq to
write this half now.

## Scope

- Portaliq: a `changeProposal` schema, a contribution action `propose-change`
  for portal subjects, an internal propose action for colleagues, and two
  leaves: a `data-provider` that lists proposals for an object and a
  `render-surface` widget where a reviewer accepts or rejects.
- Accept applies the proposed values to the subject object through the
  OpenRegister objects API under the reviewer's own rights. No app-to-app
  call; the owning app sees a normal object update.

## Out of scope

- Proposals that add or delete objects. Field changes only.
- Notifying the proposer. The OR notification engine and the inbox already
  cover a status change on an object the subject may read.

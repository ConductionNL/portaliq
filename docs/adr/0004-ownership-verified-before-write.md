# 0004. Single-object reads and writes re-verify ownership and never reveal which ids exist

- Status: accepted
- Source: [`openspec/changes/archive/2026-09-07-portal-scoped-crud/design.md`](../../openspec/changes/archive/2026-09-07-portal-scoped-crud/design.md)
  (decisions D1 to D4)

## Context

The portal read and wrote lists under a per-row ownership boundary. Adding a
single-object read and a verified update on `.../collections/{register}/{schema}/{id}`
opened the risk of a second, weaker code path and of trusting a client-supplied id.

## Decision

- `PortalObjectWriter` re-reads the row by id and continues only when the row's
  scope field matches the subject and the tenant matches. Otherwise nothing is
  written.
- After merging the patch, the writer re-stamps the scope field and organisation,
  so a patch can never move a row into or out of another subject's scope.
- A foreign-owned id and a non-existent id both return the same 404, so the API
  is not an existence oracle.
- The single-object read reuses the list read's scoping helpers, so the two
  boundaries cannot drift apart.
- Updates are direct-scoped only; patching through a claim or join scope was
  deferred.

## Consequences

- The client-supplied id selects a candidate row but is never a capability.
- Unit tests pin that a foreign-owned update never reaches `saveObject`.

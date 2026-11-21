# 0005. Field projection runs in the reader, after row verification

- Status: accepted
- Source: [`openspec/changes/archive/2026-09-07-field-projection/design.md`](../../openspec/changes/archive/2026-09-07-field-projection/design.md)
  (decisions D1 to D3)

## Context

A contribution can declare which `fields` of a row reach the portal. Projection
could live in the controller or in the reader.

## Decision

Projection is applied in the reader, as the last step after row verification, so
any caller of the reader gets projected rows. Projection never changes which rows
are selected. A projected row keeps its flat `id` and `uuid` and a reduced
`@self` with only its identifiers, so the SPA can still key rows without the
full metadata envelope leaking. An absent `fields` declaration returns the full
row; other edge cases fail closed.

## Consequences

- The reader is the single choke point for both verification and projection
  (today `PortalObjectReader` delegates the shaping to `PortalFieldProjector`).
- A contributor who wants the full envelope must declare `@self` explicitly.

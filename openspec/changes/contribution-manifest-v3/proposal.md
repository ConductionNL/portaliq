# Proposal: contribution-manifest-v3 (UI configuration vocabulary)

## Why

The contribution manifest (ADR-046, contract v2.2) tells Portaliq **which**
OpenRegister collections a subject may see and **which** actions they may take.
It says nothing about **how** to render them — so the portal can only show a
generic table + a generic create form.

ADR-063 established that "the UI is OpenRegister data": the softwarecatalogus /
tilburg-woo **Engine B** renders schema-driven tables, forms, and detail views
from a per-type configuration that today lives **in code** (factories). The
manifest *is* Engine B's serialized config — if it carries the UI config, a
contributing app ships a schema + a manifest and its external subjects get a
real client interface (tickets, invoices, hour-slip approval, contracts) with
**no bespoke frontend per app**.

This change freezes that UI-configuration vocabulary as an **additive** contract
extension: every new key is optional with a v2-equivalent default, so every
existing provider and manifest keeps working unchanged.

## What (vocabulary, all optional & additive)

- **Collections** gain presentation hints: `columns` (per-column label + render
  kind), `detail` (single-object layout), `defaultSort`, `defaultFilters`.
- **Actions** gain form hints: `fieldConfigs` (per-field label/visible/required/
  disabled/size/placeholder/help), `optionsProviders` (static or a
  **subject-scoped live-OR-collection dropdown**), `submitLabel`,
  `successMessage`.
- **Contributions** gain `pages`: an optional composition arranging a
  contribution's collections/actions into screens out of typed **blocks**
  (`collection` / `action` / `detail` / `richText` / `cta`). Absent → Portaliq
  auto-composes one page per listable collection (v2 behaviour).

## The security invariant (non-negotiable)

**UI config is presentation-only; it can never widen data access.** The
authorities remain exactly where contract v2 put them:

1. The action's server-side `fields` whitelist is the sole authority over what a
   create/update accepts — a `fieldConfig` marking a field visible/required does
   **not** add it to the whitelist.
2. Collection scope (`verifyScope` / `via` / `scopeClaim`) and read-side field
   projection remain the sole authority over what data is returned — a `column`
   naming a projected-away field renders blank, never leaks it.
3. `optionsProviders` of kind `collection` fetch through the **subject-scoped**
   `/portal/api/collections/{register}/{schema}` endpoint, so a dropdown can only
   ever list rows the subject may already read — a provider cannot point one at
   an unscoped register.
4. Validation is **fail-closed**: a malformed page/block/column/optionsProvider
   is dropped (never rendered, never fatal); a block referencing a collection or
   action id absent from the *same* contribution is dropped (no cross-
   contribution references).

## Out of scope

- The frontend engine adoption (Phase 3) — this change only defines + validates
  the contract and surfaces it in the aggregate.
- Rich block types beyond the base registry (payment CTA → A6, approve/reject →
  update endpoint): named here, specified when Phase 4/5 wire them.
- Per-Organisation white-label theming (Phase 3/6).

## Dependencies

- Builds on contract v2.2 (`portal-contribution-contract`).
- The `detail` layout and the `type: update` block become fully exercisable once
  `portal-scoped-crud` (single-object read + verified update, PR #25) lands; the
  vocabulary is defined independently and degrades gracefully without it.

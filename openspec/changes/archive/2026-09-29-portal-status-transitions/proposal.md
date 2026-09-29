# Proposal: portal-status-transitions (approve/reject/close as server-enforced transitions)

## Why

Contribution-manifest-v3 (ADR-063) gave collections a `pages` layout and actions
a schema-driven `create`/`update` form. The next real workflow the portal needs
is a **status transition** — approve / reject / close / pay — surfaced as a
per-row button, not a free-form edit form. The transition target (e.g.
`status: closed`) must be **fixed by the app, enforced by the server**, and NOT
chooseable by the client, or a supplier could set any status on their own row.

This change adds the minimal vocabulary + enforcement for that, building on the
scoped `PATCH` update endpoint (portal-scoped-crud) and the fail-closed
normaliser (contribution-manifest-v3).

## What (additive vocabulary + enforcement)

- **Action `set`** (on a `type: update` action): a map of **whitelisted** field
  → fixed value the SERVER applies over the client input. The transition target
  is enforced server-side — the client sends no field data (or its value is
  overwritten). Only keys in the action's `fields` whitelist with scalar values
  survive normalisation.
- **Collection `rowActions`**: a list of action-ids that resolve to `type: update`
  actions in the same contribution, surfaced as per-row transition buttons.
- **`?action=<id>` disambiguator** on `PATCH /portal/api/collections/{register}/
  {schema}/{id}`: names WHICH update action to apply, so a specific transition
  (e.g. `closeExample` with `set: {status: closed}`) is used rather than the
  first update action for the schema. Absent = the v1 first-match behaviour.

## The security invariant

The transition target is **tamper-proof**. The server applies `set` AFTER
whitelisting the client body, honouring only fields already in the action's
`fields` whitelist, and the writer re-verifies ownership + re-stamps the scope
before any write. A client that PATCHes `{status: "reopened-by-hacker"}` against
a `set: {status: closed}` transition still lands on `closed`. `rowActions` and
`set` are dropped fail-closed when malformed or referencing a non-update /
non-whitelisted target — presentation config can never widen write access.

## Out of scope

- Payment CTA (A6 → PSP) and file upload — separate Phase-4/5 blocks.
- A transition *guard* (allowed from-states) — a follow-up; today any owned row
  can be transitioned, which suits close/approve but not a strict state machine.

## Dependencies

- Builds on `portal-scoped-crud` (the `PATCH` update endpoint) and
  `contribution-manifest-v3` (the normaliser + `pages`/`rowActions` rendering),
  both merged to development. Extends ADR-063's vocabulary.

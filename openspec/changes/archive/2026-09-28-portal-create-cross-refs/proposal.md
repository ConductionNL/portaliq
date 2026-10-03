---
kind: code
---

# Proposal: portal-create-cross-refs

Competitor gap register, row 6.7 "Messaging with citizens through a portal"
(`procest/_gaps/gap-register.md` in ConductionNL/market-intelligence). The
dossiq side of this was archived on 2026-09-09 as `move-portals-to-portaliq`
with one task open, T7, and that task is blocked on exactly this.

## Why

A portal write is a flat map. The action whitelists which fields the client
may send, the server stamps ownership onto one of them, and everything else
is taken as typed. A uuid in one of those fields is therefore accepted
without anyone asking whose object it names.

That is why three portal writes were never shipped. A citizen filing a
bezwaar names the case they object to; a citizen replying to a message names
the thread; an external inspector submitting a run names the case and the
template. Each of those is a reference to an object the sender may not be
entitled to, and the flat writer cannot tell.

The refusal cannot live in the domain app either, because the domain app
never sees the portal subject: Portaliq resolves the bearer, and the write
arrives at OpenRegister already stamped.

## What changes

- A create or update action MAY declare `crossRefs`: per whitelisted field,
  the register, schema and scope field the value must resolve inside.
- Before any write, Portaliq resolves each declared reference through the
  same scoped read it uses to show a subject one of their own objects. A
  reference that does not resolve refuses the whole write with 403
  `cross_ref_refused` and the field that failed.
- A declaration that cannot be read removes the ACTION rather than the
  declaration. Every other normaliser here drops a malformed block and keeps
  the entry, because dropping a block closes a surface. This block is the
  guard, so dropping it alone would leave the write standing unguarded.
- `crossRefs` and `anonymous` are mutually exclusive, and the guard survives:
  an anonymous caller owns nothing, so there is no scope to check against.

## Ownership

Portaliq. The portal is the only party that holds the subject at write time,
so the reference check belongs to the portal rather than to the app whose
schema is being written.

## Capabilities

- Modified: `portal-contribution-contract`: an action can declare which of
  its fields are references, and a reference outside the subject's own scope
  refuses the write.

## Impact

`lib/Contribution/CrossRefConfigNormaliser.php` (new),
`lib/Contribution/ActionConfigNormaliser.php`,
`lib/Service/PortalCrossRefGuard.php` (new),
`lib/Controller/ContributionController.php`; unit tests.

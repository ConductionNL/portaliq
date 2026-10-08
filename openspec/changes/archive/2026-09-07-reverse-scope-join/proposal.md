---
kind: code
---

# Proposal: reverse-scope-join

## Summary

Extend the portal reader's one-hop `via` join (contract v2 A5) with an optional
`match` discriminator so the join can run in the **reverse** direction. Today
`via` is forward-only: it resolves the subject → a set of `targetField` values
and keeps outer rows whose OWN `id`/`uuid` is in that set (the join row
references the outer object by id — zaakafhandelapp `rol`→`zaak`). Reverse
scoping — where the outer rows carry a **foreign scope key** and you keep rows
whose `scopeField` VALUE is in the set — cannot be expressed. `match: 'id'`
(the default when absent) is the existing behaviour byte-for-byte;
`match: 'scopeField'` is the new reverse mode. Nothing else about the join
changes: the per-row verified pre-pass, the row cap, and the tenant discipline
are identical in both directions. Tracked by Conduction/portaliq#14.

## Motivation

scholiq's parent audience needs "a guardian sees their children's grades":
guardian → `learner-profile.guardianRefs` (the join) → the children's learner
refs → `grade-entry` rows WHERE `grade-entry.learnerRef` ∈ those children. The
grade rows carry a **foreign key** (`learnerRef`) pointing at the children; the
guardian is never named on a grade. The shipped forward `via` can only keep
grades whose OWN id is a resolved value — the wrong direction — so scholiq
would have to either denormalise a `guardianRef` onto every grade or contribute
no grade collection at all. A one-word `match: 'scopeField'` discriminator on
the existing declaration closes the gap in the one join path every portal read
already flows through, without touching the forward path or the join pre-pass
that is the security boundary.

This frames as ADR-046 A5 extension / contract v2.2 — the same declarative,
optional, v1-compatible-default spirit as `minTrust` / `scopeClaim` / `via` /
`fields`.

## Affected Projects

- [ ] Project: `portaliq` — reader gains the reverse `match` mode on
  `via` (join pre-pass unchanged; only how the verified set is applied to the
  outer rows), `via` structural validation accepts+bounds `match`, the demo
  provider declares one reverse-join collection, README documents `via.match`.

No other project changes: contributing apps adopt `match: 'scopeField'`
declaratively in their own manifests (no code); the controller already
forwards the whole `via` array, so `match` rides along untouched.

## Scope

### In Scope

- `via.match` discriminator on a collection's `via`: `'id'` (default —
  forward, existing) or `'scopeField'` (reverse, new).
- Reverse matching: keep outer rows where the value at the collection's own
  `scopeField` (dot-path) is in the verified target set — scalar equality OR
  strict element-wise array-contains for a multi-value field.
- `isValidVia()` bounds `match`: optional, and when present exactly `'id'` or
  `'scopeField'` — any other value fails the via closed (zero rows).
- Fail-closed edges preserved: empty verified set → zero rows (never all);
  absent/null outer `scopeField` → excluded; the outer per-row tenant check is
  applied in BOTH modes; the join pre-pass (per-row dot-path verification, row
  cap, tenant) is untouched.
- Field projection still runs AFTER filtering, so reverse-joined rows project.
- Demo provider: one reverse-join collection (existing schemas only) so the
  portal stays the exercisable reference.
- README: document `via.match`; keep the `portal-contribution-contract`
  capability spec in sync (delta + main).

### Out of Scope

- The forward `match: 'id'` path — unchanged by design (regression-pinned).
- More than one hop, or reversing anything other than the outer `scopeField`
  (the collection already declares that field).
- Any register/schema edit, migration, seed, or SPA change.
- A realistic domain reverse-join seed (guardian→learner→grades) — that lives
  in scholiq's own `portal-contribution` change with scholiq's schemas.

## Approach

`readViaCollection()` keeps its verified pre-pass exactly as is; only the final
"apply the target set to the outer rows" step becomes mode-aware. The mode is
read from the (already validated) `via['match']`, defaulting to `'id'`, and
threaded — together with the collection's own `scopeField` — into
`filterTargetRows()`, whose per-row membership check branches: `'id'` runs the
original `rowIds()` membership untouched, `'scopeField'` runs the new
`dotGet($row, $scopeField)` membership over the same strict set. The controller
needs no change — it already passes both the whole `via` and the collection's
`scopeField`. Details and the security invariants are in design.md.

## New Dependencies

None.

## Impact

- `lib/Service/PortalObjectReader.php` — `readViaCollection()` threads
  `scopeField` + `match`; `filterTargetRows()` gains the two parameters and a
  per-row `rowInTargetSet()` mode branch; `isValidVia()` bounds `match`.
- `lib/Portal/PortalContributionProvider.php` — one reverse-join demo
  collection.
- `tests/Unit/Service/PortalObjectReaderTest.php` — the reverse-match matrix +
  forward regression pins.
- `README.md` — `via.match` in the contract vocabulary.
- API shape: unchanged routes; a collection declaring `match: 'scopeField'`
  simply selects rows by the reverse rule.

## Cross-Project Dependencies

- **Consumed by (later)**: scholiq (parent audience — `grade-entry` via
  `learner-profile`) declares `match: 'scopeField'` in its own
  `portal-contribution` change; no portaliq follow-up per app.
- **Consumes**: nothing new; OpenRegister usage is untouched (the reverse rule
  is post-read, in-memory).

## Risks

### Risk 1: Reverse match widens the read (fails open)

**Severity:** High — **Mitigation:** the pre-pass is unchanged and remains the
boundary; an empty verified set returns zero rows before the outer read; the
per-row check uses strict set membership over the SAME string set the pre-pass
built; absent/null `scopeField` normalises to no candidates (never a wildcard).
Unit tests pin empty-set → zero rows and absent/null → excluded.

### Risk 2: `match` typo silently falls back to forward

**Severity:** Medium — **Mitigation:** `isValidVia()` accepts only the two
literals; any other value fails the WHOLE via closed (zero rows + logged
warning), never a silent forward read. Pinned by a fail-closed test over a
range of bad values.

### Risk 3: Forward behaviour drifts

**Severity:** Medium — **Mitigation:** the `'id'` branch calls the original
`rowIds()` membership unchanged; the existing forward suite stays green and a
new test pins explicit `match: 'id'` ≡ omitted `match`.

## Rollback Strategy

Revert the PR. `match` is optional with an `'id'` default and no schema /
register / data change is involved, so reverting restores forward-only
behaviour everywhere with no migration.

## Open Questions

None — semantics decided in design.md "Decisions" (which scopeField the reverse
mode reads, array-contains discipline, fail-closed `match` posture).

# portal-contribution-contract Delta: reverse-scope-join

**Status**: in-progress
**Scope**: portaliq
**OpenSpec changes**:

- [reverse-scope-join](../../)

## Purpose

Extends the one-hop `via` join enforced by portaliq (ADR-046 A5 / contract
v2.2) with an optional `match` discriminator selecting the join DIRECTION.
Forward (`match: 'id'`, the default) is unchanged; reverse
(`match: 'scopeField'`) keeps outer rows whose own `scopeField` value is in the
verified target set, for outer rows that carry a foreign scope key (scholiq
guardian → `learner-profile` → grades WHERE `learnerRef` ∈ children). Modifies
the "One-hop via join scoping" requirement created by `contract-v2`.
Related: ADR-046 (contract), ADR-005 (fail-closed security).

## MODIFIED Requirements

### Requirement: One-hop via join scoping

The reader MUST support one declared join per collection —
`via: {register, schema, scopeField, targetField, match?}` (optional). It MUST
first resolve join rows in `via.register`/`via.schema` whose `via.scopeField`
(dot-path allowed for nested properties) equals the collection's scoping value,
per-row verified; MUST collect the `targetField` references from the verified
join rows into a set; and MUST apply that set to the outer rows the way the
optional `match` discriminator selects:

- **`match: 'id'`** (the DEFAULT when absent) — *forward*: return only outer
  objects whose own `id`/`uuid` is in the set, verified per row. This is the
  original A5 behaviour, unchanged.
- **`match: 'scopeField'`** — *reverse*: return only outer rows whose value at
  the collection's own `scopeField` (dot-path allowed) is in the set — scalar
  equality, OR strict element-wise membership for a multi-value field (ANY
  element in the set matches; no loose comparison). An outer row whose
  `scopeField` value is absent or null MUST be excluded (never treated as a
  wildcard).

The join pre-pass (per-row dot-path verification, the row cap, and the tenant
check) MUST be identical in both directions and is the security boundary; the
per-row organisation verification MUST also be applied to the outer rows in
both directions. `match`, when present, MUST be exactly `'id'` or
`'scopeField'` — any other value MUST fail the whole `via` closed (zero rows +
logged warning), exactly like a structurally invalid `via`. An empty verified
join set MUST yield zero rows in BOTH modes (fail-closed empty, never all
rows). Exactly one hop is supported: a `via` declaration nested inside another
`via`, or a structurally invalid `via`, MUST yield zero rows. The join pre-pass
and outer read MUST apply the same `_rbac: false` / `_multitenancy: false` +
per-row organisation verification discipline as direct reads. Field projection,
when declared, MUST run AFTER this filtering in both modes.

#### Scenario: A case is readable because a role row links the subject

- GIVEN join rows in `zaken`/`rol` where `betrokkeneIdentificatie.inpBsn` equals the subject's scoping value and `zaak` references target UUIDs
- AND a collection on `zaken`/`zaak` declaring that `via` (no `match`, or `match: "id"`)
- WHEN the subject reads the collection
- THEN only `zaak` objects whose `id`/`uuid` appears in the verified join set are returned
- AND a `zaak` not referenced by any of the subject's join rows is never returned even if OpenRegister returns it
- @e2e exclude backend join contract — covered by PHPUnit forward-via suite (join match, target membership, foreign-row drop) plus an explicit `match:"id"` ≡ absent pin; no portaliq UI change

#### Scenario: A guardian reads grades via a reverse scopeField join

- GIVEN join rows in `scholiq`/`learnerProfile` where `guardianRefs` contains the subject's scoping value and `learnerRef` references the guardian's children
- AND a collection on `scholiq`/`gradeEntry` with `scopeField: "learnerRef"` declaring that `via` with `match: "scopeField"`
- WHEN the subject reads the collection
- THEN only `gradeEntry` rows whose own `learnerRef` is one of the verified children is returned, each re-checked per row and by tenant
- AND a grade for a child the subject does not guardian is never returned even if OpenRegister returns it
- @e2e exclude backend reverse-join contract — covered by the PHPUnit reverse-match matrix (scalar + array-element match, foreign-row drop); requires scholiq schemas, no distinct portaliq UI flow

#### Scenario: A multi-value scopeField matches on any element

- GIVEN a reverse (`match: "scopeField"`) collection whose outer rows carry a multi-value `scopeField` (e.g. `learnerRefs: [...]`)
- WHEN the subject reads the collection
- THEN a row is returned iff AT LEAST ONE element of its `scopeField` is in the verified set (strict, element-wise), and excluded when none is
- @e2e exclude strict-membership invariant — pinned by a dedicated PHPUnit case; no UI surface

#### Scenario: Reverse match never widens

- GIVEN a reverse (`match: "scopeField"`) collection AND a subject whose verified join set is empty, OR outer rows whose `scopeField` is absent/null
- WHEN the subject reads the collection
- THEN the response is 200 with zero rows — an empty set skips the outer read entirely, and an absent/null `scopeField` value is excluded (never a wildcard)
- @e2e exclude fail-closed-empty contract — covered by PHPUnit, indistinguishable from an empty collection in the UI

#### Scenario: More than one hop, or a malformed match, fails closed

- GIVEN a collection whose `via` is structurally invalid, attempts a nested join, or carries a `match` value other than `"id"`/`"scopeField"`
- WHEN the subject reads the collection
- THEN the response is 200 with zero objects and a warning is logged
- @e2e exclude defensive validation — covered by PHPUnit, no UI surface

## Non-Functional Requirements

- **Performance:** the reverse mode adds no OpenRegister queries — it is the
  same one join query (row-capped) + one outer read as forward, with an
  in-memory per-row set membership over the already row-capped result.
- **Security (ADR-005):** the reverse mode is output selection over — never a
  replacement for — the unchanged verified join pre-pass; every edge (empty
  set, absent/null `scopeField`, malformed `match`) narrows to zero rows, never
  widens; the outer tenant check runs in both modes.
- **Accessibility / i18n:** no UI change and no new user-facing strings in this
  slice.

## Acceptance Criteria

- A reverse (`match: "scopeField"`) collection returns only outer rows whose own `scopeField` value (scalar or any array element, strict) is in the subject's verified target set, each re-verified per row and by tenant
- Forward (`match: "id"` or absent) behaviour is byte-for-byte unchanged (existing forward suite green + an explicit-`id` ≡ absent pin)
- Empty verified set → zero rows (no outer read); absent/null outer `scopeField` → excluded; a `match` other than the two literals fails the via closed (zero rows + warning)
- Field projection applies to reverse-joined rows
- README documents `via.match` in the contract vocabulary section

## Notes

- Canonical contract text remains the ADR-046 amendment (hydra); `match` is
  portaliq-enforced vocabulary in the same spirit as `minTrust` / `scopeClaim`
  / `via` / `fields` — declarative, optional, forward-compatible default
  (`'id'`).
- The reverse mode reuses the collection's already-declared `scopeField` (the
  outer foreign key), so no new manifest member is introduced for the outer
  side; only the join direction is selected.
- Tracking issue: Conduction/portaliq#14.

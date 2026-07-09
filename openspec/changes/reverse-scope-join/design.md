# Design: reverse-scope-join

## Context

Contract v2 (merged) gave a collection a one-hop `via` join
(`{register, schema, scopeField, targetField}`) resolved in
`PortalObjectReader::readViaCollection()`:

1. **Join pre-pass** (`verifiedJoinTargets()`) — query `via.register` /
   `via.schema` filtered by `via.scopeField` = the subject's scoping value
   (best-effort query filter, row-capped at `JOIN_ROW_CAP` = 500), then PER ROW
   verify the dot-path scope value (`joinRowMatches()`) and tenant
   (`organisationMatches()`) — this is the security boundary — and union the
   survivors' `targetField` values into a `array<string, true>` set.
2. **Outer read** — read the collection's own register/schema and, in
   `filterTargetRows()`, keep rows whose OWN `id`/`uuid` (`rowIds()`) is in the
   set, re-checking tenant per row.

That is **forward**: the verified set holds outer-object IDENTIFIERS, and the
outer row is kept by matching its own id. It fits "the join row references the
outer object by id" (zaakafhandelapp `rol.zaak` → `zaak.id`).

It cannot express the mirror shape, where the outer rows carry a **foreign
scope key** and the verified set holds KEY VALUES to match against that key.
scholiq's parent audience is exactly that shape.

## Goals / Non-Goals

**Goals:** a declarative, optional, forward-compatible reverse mode on the
existing `via`; the join pre-pass byte-for-byte unchanged; every fail-closed
edge preserved and independently tested; zero behaviour change for a `via`
without `match` (or with `match: 'id'`).

**Non-Goals:** multi-hop joins; reversing on a field other than the
collection's own `scopeField`; register/schema/seed changes; SPA changes; a
realistic domain seed (lives in scholiq's change).

## Decisions

### D1: `match` discriminator, default `'id'`

`via` gains one optional member, `match`:

- **`match: 'id'`** — absent-default. The EXISTING forward behaviour, called
  through the unchanged `rowIds()` membership. Byte-for-byte identical.
- **`match: 'scopeField'`** — reverse. Keep an outer row when the value at the
  collection's own `scopeField` (dot-path via `dotGet`) is in the verified set.

Absent → `'id'`, so every existing manifest and the whole forward suite are
untouched. The reverse mode reuses the collection's **already-declared**
`scopeField` — the field the direct path scopes on — so no new manifest member
is introduced for the outer side; only the join *direction* is selected.

### D2: Which `scopeField` the reverse mode reads

The reverse mode reads the value at the **collection's own** `scopeField` (the
`scopeField` argument of `readCollection()` — for scholiq `grade-entry` that is
`learnerRef`), NOT `via.scopeField` (which stays the JOIN row's field, matched
against the subject in the unchanged pre-pass). The two are distinct concerns:
`via.scopeField` verifies the join row against the subject; the collection
`scopeField` is the foreign key on the outer row the verified set is matched
against. The controller already passes the collection `scopeField` into the
reader, so it is threaded into `readViaCollection()` → `filterTargetRows()`
with no controller change.

### D3: Matching discipline — strict, symmetric with the set

Both directions test membership with `isset($targets[$key])` over the SAME
`array<string, true>` set the pre-pass built via `targetRefs()` (string, or
array of non-empty strings — the OR relations convention). The reverse branch
normalises the outer row's `scopeField` value through the *same* `targetRefs()`
helper, so the two sides compare like-for-like:

- **scalar** `scopeField` — a single non-empty string candidate; matched iff in
  the set.
- **multi-value** `scopeField` (`learnerRefs: [...]`) — matched iff ANY element
  is in the set (strict, element-wise — mirrors `joinRowMatches()`'s
  `in_array($v, $set, true)` discipline; no loose compare).
- **absent / null / non-string** `scopeField` — normalises to zero candidates
  → excluded. A missing key is NEVER a wildcard.

### D4: Never widen (the whole point)

- An **empty verified set** returns zero rows before the outer read even runs
  (`readViaCollection()` returns `[]` when `count($targets) === 0`) — reverse
  cannot fall through to "all rows".
- A **malformed `match`** (anything but the two literals) fails the WHOLE via
  closed in `isValidVia()` (zero rows + logged warning), never a silent forward
  read.
- The **outer per-row tenant check** (`organisationMatches()`) runs in BOTH
  modes — reverse membership decides candidacy, tenant still decides
  admissibility.

### D5: Split, not rewrite

`filterTargetRows()` keeps its shape (normalise → membership → tenant →
collect); only the membership test is factored into a private
`rowInTargetSet($row, $targets, $match, $scopeField)` whose `'id'` branch is
the original loop verbatim. This keeps the forward path a literal no-op change
and makes the two modes readable side by side.

## The two match modes

```
subject scoping value
        │
        ▼
verifiedJoinTargets()  ── query via.register/via.schema, per-row verify
        │                 via.scopeField == subject + tenant, union targetField
        ▼
  targets: { "<value>": true, ... }        (identical in both modes)
        │
        ├── match: 'id'  (forward, default) ── keep outer row iff
        │                                       rowIds(row) ∩ targets ≠ ∅
        │
        └── match: 'scopeField' (reverse) ──── keep outer row iff
                                                targetRefs(dotGet(row, scopeField))
                                                ∩ targets ≠ ∅
        │
        ▼
  + organisationMatches(row)  (tenant, BOTH modes)
        ▼
  + projectRows(fields)       (field projection, AFTER filtering, BOTH modes)
```

## Security invariants (each pinned by a test)

1. **Pre-pass unchanged.** `verifiedJoinTargets()` (per-row dot-path
   verification, `JOIN_ROW_CAP`, tenant) is not touched by this change — the
   boundary is where it always was. *Pinned by:* the existing forward via suite
   staying green + `testForwardViaWithExplicitIdMatchIsUnchanged`.
2. **Reverse never widens.** Empty verified set → zero rows (no outer read);
   absent/null outer `scopeField` → excluded; array `scopeField` uses strict
   element-wise membership. *Pinned by:*
   `testReverseViaEmptyVerifiedTargetSetYieldsZeroRows`,
   `testReverseViaExcludesRowsWithAbsentOrNullScopeField`,
   `testReverseViaArrayScopeFieldMatchesOnAnyElement`.
3. **Outer tenant check preserved in both modes.** *Pinned by:*
   `testReverseViaTenantMismatchOnOuterRowExcluded` (+ the forward tenant path
   already covered).
4. **`isValidVia()` still requires `{register, schema, scopeField,
   targetField}`; `match` optional and exactly `'id'`/`'scopeField'` or fail
   closed; no nested via.** *Pinned by:*
   `testReverseViaMalformedMatchFailsClosed` + the existing
   `testInvalidOrNestedViaFailsClosedWithWarning`.
5. **Projection still runs after filtering** (reverse-joined rows project too).
   *Pinned by:* `testReverseViaProjectionAppliedToReverseJoinedRows`.

## Worked example (scholiq parent — NIL-UUID placeholders)

Guardian subject scoping value `00000000-0000-0000-0000-00000000AAAA`.

**Join** — register `scholiq`, schema `learnerProfile`,
`scopeField: guardianRefs`, `targetField: learnerRef`:

| learnerRef (target)                      | guardianRefs                               | verified? |
|------------------------------------------|--------------------------------------------|-----------|
| `00000000-0000-0000-0000-00000000C1D1`   | `["...AAAA"]`                              | ✅ subject guardians this child |
| `00000000-0000-0000-0000-00000000C1D2`   | `["...AAAA"]`                              | ✅ |
| `00000000-0000-0000-0000-00000000C9D9`   | `["...BBBB"]` (a different guardian)       | ❌ dropped in the pre-pass |

Verified target set = `{ ...C1D1, ...C1D2 }`.

**Outer collection** — register `scholiq`, schema `gradeEntry`,
`scopeField: learnerRef`, `via.match: 'scopeField'`:

| gradeEntry.learnerRef        | in set? | returned? |
|------------------------------|---------|-----------|
| `...C1D1`                    | yes     | ✅ |
| `...C1D2`                    | yes     | ✅ |
| `...C9D9`                    | no      | ❌ foreign child, dropped even if OR returned it |
| `...C7D7` (unrelated)        | no      | ❌ |

The guardian never appears on a grade; the grade's OWN `learnerRef` (a foreign
key to the child) is the match — the reverse of the forward zaak case, where
the zaak's own id was the match.

## Declarative-vs-imperative note

Same posture as contract-v2 and field-projection: ADR-031's declarative default
governs domain apps' business logic; the *contract itself* is the declarative
surface — a contributor expresses the join direction as manifest data
(`match: 'scopeField'`), writing no portal code. Enforcing that declaration is
**hub infrastructure on a security boundary** (it decides which rows leave the
trusted intermediary, running with OR RBAC/multitenancy deliberately off
because portal subjects do not exist in the layers a declarative OR dialect
acts on), so it stays imperative, unit-testable reader code alongside the
existing join verification — not an OpenRegister schema dialect.

## Seed Data

**No new seed objects; no register edit.** The demo provider's new
`exampleReverseJoin` collection reuses the existing `portalAccount` and
`exampleDocument` schemas and the existing contract-v2 seeds
(`dev-supplier` / `dev-client` / NIL-UUID placeholders). It is deliberately
self-referential (join `portalAccount` on `subjectRef`, keep `exampleDocument`
rows whose `subjectRef` is in the resolved set) so the reverse code path is
exercisable on a dev install without inventing schemas; the realistic
guardian→learner→grades seed lives in scholiq's own change.

## Migration Plan

Deploy: merge; no schema, register, data, or route change — behaviour changes
only for a collection that declares `match: 'scopeField'` (none in the fleet
yet except the demo). Rollback: revert the PR; the optional member defaults to
`'id'`, so forward-only behaviour returns everywhere instantly.

## Open Questions

None.

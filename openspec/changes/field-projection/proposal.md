---
kind: code
---

# Proposal: field-projection

## Summary

Add **read-side field projection** to the portal contribution contract: a
collection MAY declare `fields: ["a", "b", "c"]` and the portal reader then
returns ONLY those properties per row (plus the row identifier(s) needed for
detail links), applied after per-row verification and before anything leaves
the reader. No `fields` declaration keeps today's full-row behaviour
(backward compatible). Alongside, one small independent hardening: a unit
test pinning the exact `X-Portal-Subject` assertion wire format (A6) so
receiver-side verifiers being templated in domain apps can rely on a frozen
shape.

## Motivation

Contract v2 collections return **full rows**. Wave-1 contributor reviews had
to fail-closed EXCLUDE whole collections because rows carry staff-only
fields — pipelinq's `booking.internalNotes` is documented as "never returned
to the customer portal", and `contactmoment.notes` is internal. Without a
declarative way to expose a row *partially*, a contributing app's only safe
options are to not contribute the collection at all or to materialise a
parallel "portal-safe" schema. A `fields` whitelist on the collection
declaration closes that gap in the one aggregation/read path every portal
read already flows through, keeping the fail-closed posture: nothing
undeclared ever widens the output.

The assertion pin rides along because domain apps are templating their
receiver-side `X-Portal-Subject` verifiers (petstore, in parallel) against
the shape portaliq mints today; freezing it in a test makes any accidental
format drift a loud test failure instead of a silent fleet break.

## Affected Projects

- [ ] Project: `portaliq` — reader gains projection (list path, direct + via;
  factored so future single-object reads reuse it), controller passes the
  declared `fields` through, demo provider declares `fields` on one
  collection, assertion wire-format pin test, README vocabulary docs.

No other project changes: contributing apps adopt `fields` declaratively in
their own manifests (no code), and receiving apps keep verifying assertions
against the now-pinned shape.

## Scope

### In Scope

- `fields` vocabulary on collection declarations (any `kind`, including
  `inbox`): pure whitelist of top-level row property names, applied by
  `PortalObjectReader` AFTER per-row verification, BEFORE returning.
- Identifier preservation: projection never strips the row identifier(s)
  (`id` / `uuid`, flat or as a reduced `@self` envelope) — detail links keep
  working.
- Fail-closed edges: unknown declared fields project to absent (no error);
  a malformed `fields` declaration projects to identifiers-only, never to
  the full row; `scopeField` values are not auto-included.
- Backward compatibility: absent `fields` → full row, existing suite green.
- Demo provider: one collection declares `fields` so the portal stays the
  exercisable reference.
- Hardening: a unit test pinning the exact A6 `X-Portal-Subject` JWT shape
  (header `alg: HS256`; claims `sub`/`audience`/`organisation`/`trust`/
  `jti`/`use: "assertion"`/`iat`/`exp`(/`iss`)).
- README: document `fields` in the contract vocabulary section; keep the
  `portal-contribution-contract` capability spec in sync (delta + main).

### Out of Scope

- Write-side projection (the create whitelist already exists and is
  unchanged).
- Dot-path / nested-property projection (`fields` names match top-level row
  keys only; nested shaping is a follow-up if a contributor needs it).
- Any register/schema edit, migration, or SPA change (the SPA renders
  whatever rows arrive).
- Receiver-side assertion verification code (per-app rollout waves).

## Approach

Factor projection as a public single-row primitive on the reader
(`projectRow()`, wrapped by `projectRows()`) and apply it at the end of
`readCollection()` on both the direct and `via` paths — after
`verifyScope()` / target filtering, so projection can never *replace*
verification. The controller forwards `($collection['fields'] ?? null)`
unmodified; `null` means "no projection". Details and the exact identifier
semantics are in design.md.

## New Dependencies

None.

## Impact

- `lib/Service/PortalObjectReader.php` — `projectRow()`/`projectRows()`,
  `fields` parameter on `readCollection()` (optional, default `null`).
- `lib/Controller/ContributionController.php` — `collection()` passes the
  matched collection's `fields` to the reader.
- `lib/Portal/PortalContributionProvider.php` — demo collection declares
  `fields`.
- `tests/Unit/Service/PortalObjectReaderTest.php`,
  `tests/Unit/Controller/ContributionControllerTest.php`,
  `tests/Unit/Service/PortalJwtServiceTest.php` — projection matrix +
  assertion pin.
- `README.md` — `fields` in the contract vocabulary.
- API shape: unchanged routes; rows may simply carry fewer properties when a
  collection declares `fields`.

## Cross-Project Dependencies

- **Consumed by (later waves)**: Tier-1 contributor apps (pipelinq first —
  `booking`, `contactmoment`) declare `fields` in their own
  `portal-contribution` changes; no portaliq follow-up needed per app.
- **Consumes**: nothing new; OpenRegister usage is untouched (projection is
  post-read, in-memory).

## Risks

### Risk 1: Projection mistaken for authorisation

**Severity:** Medium — **Mitigation:** projection runs strictly AFTER the
existing per-row verification and never influences it; the spec and code
comments state it shapes *what a verified row shows*, not *which rows
return*. Unit tests assert foreign rows are still dropped when `fields` is
declared.

### Risk 2: Identifier stripped, breaking detail links

**Severity:** Medium — **Mitigation:** `projectRow()` unconditionally
retains flat `id`/`uuid` and a reduced `@self` (`id`/`uuid` only) when
present; pinned by a dedicated unit test.

### Risk 3: Malformed `fields` declaration widens output

**Severity:** Low — **Mitigation:** a declared-but-malformed `fields` value
projects to identifiers-only (fail-closed narrow); only a fully absent
declaration yields the full row.

## Rollback Strategy

Revert the PR. The reader parameter is optional with a `null` default and
no schema/register/data change is involved, so reverting restores full-row
behaviour everywhere without any migration or data cleanup.

## Open Questions

None — semantics decided in design.md "Decisions" (identifier set, malformed
declaration posture, detail-read factoring).

---
kind: code
---

# Proposal: portal-scoped-crud

## Summary

Add two subject-scoped single-object endpoints to the portal contribution API
(ADR-062 Phase 1): a **scoped single-object read**
(`GET .../collections/{register}/{schema}/{id}`) and a **scoped verified
update** (`PATCH .../collections/{register}/{schema}/{id}`). Both re-verify
per-subject ownership server-side, using the SAME per-row boundary the list
read already enforces (`row[scopeField] === subjectRef` + tenant match). The
update **re-verifies ownership against OpenRegister BEFORE any write** — the
client-supplied id is never trusted — and **re-stamps the scope field** on the
merged data, closing the write-side IDOR gap tracked by Conduction/portaliq#16.
A foreign-owned or non-existent id returns a single 404 (indistinguishable —
NO existence oracle) on both paths.

## Motivation

Contract v1/v2 shipped a scoped list read (`readCollection`) and a scoped
create (`createObject`), but no way to read or patch ONE object by id. A portal
detail page therefore had to either over-fetch the whole collection or — worse
— a naive update endpoint would trust the client-supplied id and write to it,
which is a textbook IDOR (OWASP A01:2021): any authenticated subject could
patch any other subject's row by guessing its id. #16 flags exactly this. The
fix is to make the single-object read and the update flow through the same
fail-closed per-subject ownership check the list read already proves, and to
re-verify ownership from OpenRegister before the writer touches anything.

This is the same declarative, v1-compatible spirit as the rest of the
contract: an `update` action is optional, whitelists its own `fields`, and
carries an optional `minTrust`.

## Affected Projects

- [ ] Project: `portaliq` — `PortalObjectReader` gains `readObject()` (scoped
  single read, reusing verifyScope + the one-hop via machinery + projection);
  `PortalObjectWriter` gains `updateObject()` (ownership re-verified against OR
  before any write, scope re-stamped, id-preserving save); the controller gains
  `object()` + `update()` handlers with two new routes; the demo provider
  declares one `type: update` action; README documents both endpoints.

No other project changes: contributing apps declare a `type: update` action in
their own manifests (no code).

## Scope

### In Scope

- `readObject(register, schema, scopeField, subjectRef, id, organisation?,
  scopeClaim?, contributingApp?, via?, audience?, fields?)`: resolve the scope
  value exactly as `readCollection` does (scopeClaim → resolveClaim, else
  subjectRef), fetch by id, run through `verifyScope` (direct) or the one-hop
  `via` membership (via), then `projectRow`. Null (→ 404) on foreign owner,
  wrong tenant, absent/malformed claim, non-member, non-existent id, or OR error.
- `updateObject(register, schema, scopeField, subjectRef, organisation, id,
  data)`: (1) re-read the row by id and confirm ownership via the SAME scope
  check; (2) merge whitelisted `data`; (3) re-stamp scopeField + organisation;
  (4) save with the id preserved (`uuid`) so OR updates. Null on any ownership
  failure (no write) or OR error.
- Controller `object()` + `update()`: subject (401) → authorised collection /
  authorised `type: update` action (403, honouring `?collection=` + minTrust
  re-check exactly like `collection()` / `create()`) → whitelist (update) →
  reader/writer → 404 if null.
- Two routes (GET + PATCH single) before the SPA catch-all; correct
  `#[PublicPage]` `#[NoCSRFRequired]` under the existing PortalProtected /
  PortalAuthMiddleware pattern.
- Demo provider: one `type: update` action so the patch path is exercisable.
- Adversarial unit tests pinning every isolation property (see design.md).

### Out of Scope

- Delete (`type: delete`) — a later ADR-062 phase.
- Scope-claim / via on the UPDATE path — update is direct-scoped only (read
  supports scopeClaim + via for detail views of those collections).
- Any register/schema edit, migration, seed, or SPA change.
- Optimistic concurrency (If-Match/version) — noted as a follow-up.

## Approach

`readObject` reuses the reader's existing private helpers (`resolveClaim`,
`verifyScope`, `verifiedJoinTargets`/`filterTargetRows`/`isValidVia`,
`projectRow`, `rowIds`, `normalise`) so the single-object boundary is
byte-for-byte the list boundary — only the fetch (by id) and the return
cardinality differ. `updateObject` does its own fetch+verify (mirroring the
writer's existing self-contained `normalise`/`objectService` style) so no write
can precede the ownership check, then merges + re-stamps + saves with the id
preserved. The controller mirrors `create()`'s authorisation discipline for
`update()` and `collection()`'s for `object()`. Security invariants and the
no-existence-oracle 404 are detailed in design.md.

## New Dependencies

None.

## Impact

- `lib/Service/PortalObjectReader.php` — `readObject()` + private `fetchById()`,
  `verifyViaObject()`.
- `lib/Service/PortalObjectWriter.php` — `updateObject()` + private
  `fetchOwnedObject()`, `rowIds()`, `organisationMatches()`.
- `lib/Controller/ContributionController.php` — `object()`, `update()`,
  `authorisedUpdateAction()`.
- `lib/Portal/PortalContributionProvider.php` — one `type: update` action.
- `appinfo/routes.php` — GET + PATCH single-object routes.
- `tests/Unit/**` — the adversarial isolation matrices (reader, writer,
  controller).
- `README.md` — both endpoints + the `type: update` vocabulary.

## Cross-Project Dependencies

- **Consumed by (later)**: any contributing app declaring a `type: update`
  action (procest/scholiq detail edits) — declarative, no portaliq follow-up.
- **Consumes**: nothing new; OpenRegister `findAll` (by-id fetch) + `saveObject`
  (id-preserving update) already used by the reader/writer.

## Risks

### Risk 1: Write-side IDOR (the whole point of #16)

**Severity:** High — **Mitigation:** `updateObject` re-reads the row from OR
and confirms `row[scopeField] === subjectRef` + tenant BEFORE any write; a
mismatch returns null with `saveObject` never called (pinned by a test that
asserts the write never happens). The scope field is re-stamped after the merge
so the row can never move out of scope even if the field reached the whitelist.

### Risk 2: Existence oracle on the single-object paths

**Severity:** Medium — **Mitigation:** both a foreign-owned id and a
non-existent id return the identical 404 (`{"error":"not_found"}`); ownership
is checked in the service layer, so the controller cannot distinguish the two.

### Risk 3: A patch silently creating a new row instead of updating

**Severity:** Low — **Mitigation:** `saveObject` is called with the id via
`uuid`, so OR updates the existing row; a test pins the saved uuid.

## Rollback Strategy

Revert the PR. The two routes/handlers/service methods are additive and no
schema / register / data change is involved, so reverting removes the endpoints
with no migration.

## Open Questions

None — semantics decided in design.md "Decisions" (no-existence-oracle 404,
ownership-before-write ordering, scope re-stamp, direct-scoped update only).

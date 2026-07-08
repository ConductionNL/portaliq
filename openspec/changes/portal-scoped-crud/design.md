# Design: portal-scoped-crud

## Context

Portaliq scopes every portal read/write by a per-row ownership boundary —
`row[scopeField] === subjectRef` plus the tenant check — enforced in
`PortalObjectReader::verifyScope()` (list read) and stamped in
`PortalObjectWriter::createObject()` (create). This change adds the two missing
single-object operations (ADR-062 Phase 1): a scoped single-object READ and a
scoped verified UPDATE, both hanging off `.../collections/{register}/{schema}/{id}`.
The update is the security-critical half: it closes the write-side IDOR concern
in Conduction/portaliq#16.

## Goals

- The single-object read and the update use the SAME per-subject boundary as
  the list read — no second, weaker code path.
- The update NEVER trusts the client-supplied id: ownership is re-verified
  against OpenRegister before anything is written.
- No existence oracle: a foreign-owned id and a non-existent id are
  indistinguishable (identical 404).

## Decisions

### D1 — Ownership is re-verified against OR BEFORE any write

`updateObject()` orders its steps so no write can precede the check:

1. `fetchOwnedObject()` re-reads the row by id and returns it **only** when
   `row[scopeField] === subjectRef` and the tenant matches — the identical
   boundary to the reader's `verifyScope()`. Any mismatch (foreign owner, wrong
   tenant, non-existent id) returns null.
2. If (1) returned null, `updateObject()` returns null immediately — `saveObject`
   is never reached.
3. Only an owned row proceeds to merge → re-stamp → save.

The client-supplied id is thus never a capability: it selects a candidate row,
but the row's own `scopeField` value (server data, not client input) decides
whether the write is allowed. Quoted code path:

```php
$existing = $this->fetchOwnedObject(...);   // row[scopeField] === subjectRef + tenant, else null
if ($existing === null) {
    return null;                            // → 404, saveObject NEVER called
}
$merged = array_merge($existing, $data);
unset($merged['@self']);
if ($scopeField !== '') { $merged[$scopeField] = $subjectRef; }   // re-stamp
if ($organisation !== '') { $merged['organisation'] = $organisation; }
$saved = $objectService->saveObject(object: $merged, register: $register, schema: $schema, uuid: $id, _rbac: false, _multitenancy: false);
```

`tests/Unit/Service/PortalObjectWriterTest.php::testUpdateRefusesToPatchAForeignOwnedObjectAndNeverWrites`
pins the invariant by asserting `saveCalled === false` when the id belongs to
another subject.

### D2 — The scope field is re-stamped (defense in depth)

Even though the scope field is never in a contribution's `fields` whitelist
(the controller's `whitelist()` only copies declared fields, and `claims` is
additionally dropped), `updateObject()` re-stamps `scopeField = subjectRef`
(and `organisation`) AFTER merging `$data`. So a patch can never move a row out
of — or into — another subject's scope, even if a scope field somehow reached
the merged data. Pinned by `testUpdateReStampsScopeEvenWhenClientSneaksItIn`.

### D3 — No existence oracle: a single 404 on both paths

Both `object()` and `update()` return `{"error":"not_found"}` / 404 whenever the
service returns null, and the service returns null for BOTH "not the subject's"
and "does not exist". Ownership is decided in the service layer, so the
controller has no way to distinguish the two and cannot leak which ids exist.
This mirrors the list read, where a foreign row simply never appears.

### D4 — The read reuses the list boundary exactly

`readObject()` calls the reader's existing private helpers rather than
re-implementing scoping: `resolveClaim()` (scopeClaim → value, fail-closed
empty → null), `verifyScope()` for a direct collection, and the one-hop via
machinery (`isValidVia` → `verifiedJoinTargets` → `filterTargetRows`) for a
`via` collection, then `projectRow()`. The ONLY differences from
`readCollection()` are the fetch (by id, `fetchById()`) and the cardinality
(one object or null). This guarantees the single-object boundary can never
drift from the list boundary.

### D5 — id-preserving save (update, not create)

`saveObject` is called with `uuid: $id`, so OpenRegister updates the existing
row instead of inserting a new one. `testUpdatePatchesTheSubjectsOwnObjectAndReStampsScope`
pins the saved uuid and that unrelated fields survive (a true PATCH).

### D6 — Update is direct-scoped only

The READ supports scopeClaim + via (detail views of those collections); the
UPDATE is direct-scoped (`scopeField === subjectRef`) only. Patching through a
claim- or join-derived scope adds attack surface without a Phase-1 use case, so
it is deferred. The `type: update` action therefore carries `register`,
`schema`, `fields`, optional `minTrust` — no `scopeClaim`/`via`.

## Isolation properties → pinning tests

| Property | Test |
|---|---|
| Read: subject's own object returned, projected | `PortalObjectReaderTest::testReadObjectReturnsTheSubjectsOwnProjectedObject` |
| Read: foreign-owned id → null | `...::testReadObjectReturnsNullForAForeignOwnedObject` |
| Read: foreign tenant → null | `...::testReadObjectReturnsNullForAForeignTenant` |
| Read: non-existent / empty id → null | `...::testReadObjectReturnsNullForANonExistentId` |
| Read: scopeClaim resolved server-side | `...::testReadObjectResolvesTheScopeClaimServerSide` |
| Read: absent claim → null, no fetch | `...::testReadObjectReturnsNullWhenScopeClaimAbsentWithoutFetch` |
| Read: via single read verifies membership | `...::testReadObjectViaCollectionVerifiesJoinMembership` |
| Write: patches own object, scope re-stamped, id-preserving | `PortalObjectWriterTest::testUpdatePatchesTheSubjectsOwnObjectAndReStampsScope` |
| Write: foreign-owned id REFUSED, no write | `...::testUpdateRefusesToPatchAForeignOwnedObjectAndNeverWrites` |
| Write: foreign tenant refused, no write | `...::testUpdateRefusesAForeignTenantBeforeAnyWrite` |
| Write: scope re-stamped over sneaked-in value | `...::testUpdateReStampsScopeEvenWhenClientSneaksItIn` |
| Write: OR error → null (fail-closed) | `...::testUpdateFailsClosedOnOpenRegisterWriteError` |
| Controller: 404 identical for absent + not-owned | `ContributionControllerTest::testObjectNullFromReaderIs404NoOracle`, `...::testUpdateOwnershipFailureFromWriterIs404` |
| Controller: 401/403 auth paths | `...::testObject*Is401/403*`, `...::testUpdate*Is401/403*` |
| Controller: whitelist applied, `claims` dropped | `...::testUpdateAppliesWhitelistAndReturnsUpdatedObject` |

## Risks / trade-offs

- **A patch could report 404 for a genuine OR write failure.** Because
  `updateObject` returns null for both ownership failure and OR error, a rare
  transient OR write error surfaces as 404 rather than 502. Accepted: the
  no-existence-oracle property dominates, and the operation is idempotent to
  retry. (The create path can afford 502 because it has no ownership secret to
  protect.)
- **No optimistic concurrency.** Two concurrent patches last-writer-wins.
  If-Match/version is a follow-up, out of Phase-1 scope.

## Migration / rollback

Purely additive (two routes, two service methods, two controller handlers, one
demo action). No schema/register/data change; revert the PR to remove.

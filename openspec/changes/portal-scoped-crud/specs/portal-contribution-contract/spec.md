# portal-contribution-contract Delta: portal-scoped-crud

**Status**: in-progress
**Scope**: portaliq
**OpenSpec changes**:

- [portal-scoped-crud](../../)

## Purpose

Adds two subject-scoped single-object operations to the portal contribution
contract (ADR-062 Phase 1): a scoped single-object READ and a scoped verified
UPDATE, each re-verifying per-subject ownership server-side with the SAME
per-row boundary the list read enforces. The update re-verifies ownership
against OpenRegister BEFORE any write (the client-supplied id is never trusted)
and re-stamps the scope field, closing the write-side IDOR concern
(Conduction/portaliq#16). Related: ADR-046 (contract), ADR-005 (fail-closed
security), ADR-022 (reads via OpenRegister).

## ADDED Requirements

### Requirement: Scoped single-object read

The reader MUST expose a single-object read that returns ONE object by id,
scoped to the subject by the SAME per-row ownership boundary as the list read.
It MUST resolve the scoping value identically to the list read (a declared
`scopeClaim` → the server-resolved claim from the subject's own portalAccount,
else the subjectRef; an absent or malformed claim MUST fail closed to "not
found" WITHOUT fetching the object). It MUST fetch the object by id, then:
for a direct collection, re-check `row[scopeField]` equals the scoping value
and the tenant matches; for a `via` collection, require the object to pass the
one-hop join membership (the identical verified pre-pass, `match` mode, and
tenant discipline as the list read). Field projection, when declared, MUST run
before returning. An object owned by a different subject, in a different
tenant, not a join member, with an absent/malformed claim, or with an id that
does not exist MUST ALL return the identical "not found" result — there MUST be
NO existence oracle distinguishing "not yours" from "does not exist". The read
MUST fail closed (missing OpenRegister, OR error, malformed row) to "not
found". The controller MUST answer `GET .../collections/{register}/{schema}/{id}`
with the object (200) or 404, after authorising the collection exactly like the
list read (manifest membership honouring `?collection=`, plus the matched
collection's `minTrust` re-checked — 403 before any OpenRegister call).

#### Scenario: A subject reads its own object by id

- GIVEN a collection the subject is entitled to AND an object whose `scopeField` equals the subject's scoping value
- WHEN the subject requests that object by id
- THEN the object is returned (200), projected to the collection's `fields` when declared
- @e2e exclude backend single-read contract — covered by the PHPUnit reader/controller matrices (own-object, projection, scope-param plumbing); no distinct portaliq UI flow

#### Scenario: A foreign-owned or absent id is an identical 404

- GIVEN a subject AND an id that either belongs to a DIFFERENT subject (or tenant) or does not exist
- WHEN the subject requests that id
- THEN the response is 404 with the identical body in both cases — no existence oracle
- @e2e exclude no-oracle security invariant — covered by PHPUnit (foreign-owner drop, foreign-tenant drop, non-existent id) all returning null → 404; no UI surface

#### Scenario: A via-collection single read verifies join membership

- GIVEN a `via` collection AND an object referenced by one of the subject's verified join rows
- WHEN the subject reads that object by id
- THEN it is returned; AND an object NOT referenced by any of the subject's join rows is 404 even if OpenRegister returns it
- @e2e exclude backend via single-read contract — covered by the PHPUnit reverse/forward via reuse; no distinct UI flow

### Requirement: Scoped verified update

The writer MUST expose a verified update that patches ONE object by id, and
MUST re-verify ownership against OpenRegister BEFORE any write: it MUST re-read
the row by id and confirm `row[scopeField]` equals the subject's reference AND
the tenant matches (the SAME boundary as the reader's per-row check); if the
row is not the subject's — foreign owner, wrong tenant, or non-existent id — it
MUST return "not found" and MUST NOT call the OpenRegister save at all. The
client-supplied id MUST NEVER be trusted as a capability. On an owned row it
MUST merge only the already-whitelisted fields onto the existing object,
re-stamp the scope field (and organisation) AFTER the merge so a patch can
never move the row out of the subject's scope, and save with the id preserved
so OpenRegister UPDATES rather than creates. The update MUST fail closed (OR
error, missing OpenRegister) to "not found". The controller MUST answer
`PATCH .../collections/{register}/{schema}/{id}` after authorising a declared
`{id, type: 'update', register, schema, fields, minTrust?}` action (403 if
none, honouring the matched action's `minTrust` re-check before any write) and
whitelisting the request body to the action's `fields` (the scope field is
never whitelisted, and `claims` is always dropped); a null result is 404, no
existence oracle.

#### Scenario: A subject patches its own object

- GIVEN a `type: update` action for a collection the subject is entitled to AND an object the subject owns
- WHEN the subject PATCHes whitelisted fields on that object by id
- THEN only the whitelisted fields change, unrelated fields are preserved, the scope field is re-stamped, and OpenRegister updates the row (id preserved)
- @e2e exclude backend update contract — covered by the PHPUnit writer/controller matrices (own-object patch, whitelist, scope re-stamp, id-preserving save); no distinct portaliq UI flow

#### Scenario: A patch to a foreign-owned id is refused before any write (IDOR)

- GIVEN a subject AND an id that belongs to a DIFFERENT subject (or tenant)
- WHEN the subject PATCHes that id
- THEN ownership is re-verified against OpenRegister FIRST, the write is refused (the OpenRegister save is never called), and the response is 404 — closing Conduction/portaliq#16
- @e2e exclude write-IDOR security invariant — pinned by a PHPUnit test asserting `saveObject` is never called for a foreign id; no UI surface

#### Scenario: The scope field is re-stamped even if a client sneaks it in

- GIVEN a subject patching its own object AND a request body that (against contract) carries a scope field value
- WHEN the update merges and saves
- THEN the scope field is re-stamped to the subject's reference, so the row can never move out of the subject's scope
- @e2e exclude defense-in-depth invariant — covered by PHPUnit; no UI surface

#### Scenario: An update without a declared update action is forbidden

- GIVEN a subject AND a (register, schema) with no `type: update` action in the subject's manifest (a `type: create` action does NOT authorise an update)
- WHEN the subject PATCHes an object there
- THEN the response is 403 and no write is attempted
- @e2e exclude authorisation contract — covered by the PHPUnit controller suite; no UI surface

## Non-Functional Requirements

- **Performance:** the single read is one by-id OpenRegister fetch (plus the
  join pre-pass for a `via` collection); the update is one by-id fetch
  (ownership) + one id-preserving save — no full-collection scan.
- **Security (ADR-005):** every edge fails closed to "not found" / 403; the
  update re-verifies ownership before any write and never trusts the client
  id; the single-object read and the update reuse the list read's exact per-row
  boundary; no path leaks an existence oracle.
- **Accessibility / i18n:** no UI change and no new user-facing strings in this
  slice.

## Acceptance Criteria

- The single-object read returns only the subject's own object; a foreign-owned, foreign-tenant, non-member, or non-existent id all return an identical 404
- The update re-verifies ownership against OpenRegister before any write; a foreign id is refused with the save never called (write-IDOR closed, #16)
- The whitelisted fields are merged, the scope field is re-stamped, and the save preserves the id (update, not create)
- Both endpoints authorise via the subject's manifest (collection / `type: update` action) with the `minTrust` re-check before any OpenRegister call
- README documents both endpoints and the `type: update` vocabulary

## Notes

- Canonical contract text remains the ADR-046 amendment (hydra) + ADR-062
  (portal dynamic-UI plan); the `type: update` action and the single-object
  endpoints are portaliq-enforced vocabulary in the same declarative, optional,
  v1-compatible spirit as `minTrust` / `scopeClaim` / `via` / `fields`.
- The update is direct-scoped only (no `scopeClaim`/`via` on the write path);
  delete and optimistic concurrency are later ADR-062 phases.
- Tracking issue: Conduction/portaliq#16.

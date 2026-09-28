# portal-contribution-contract Delta: portal-scope-list-membership

**Status**: in-progress
**Scope**: portaliq
**OpenSpec changes**:

- [portal-scope-list-membership](../../)

## Purpose

A direct collection or action may name a list field as its `scopeField`: the
row belongs to every subject whose scope value is in that list (learniq
`Submission.learnerRefs`, `LearnerProfile.guardianRefs`). The reverse `via`
join already matches a list element; this delta gives the direct path the same
strict membership, on every read and every write, and fails closed on any other
shape. Related: ADR-046 (contract), ADR-005 (fail closed), ADR-022 (reads via
OpenRegister).

## ADDED Requirements

### Requirement: A direct scope field MUST match a single value or strict list membership

Wherever portaliq checks a row's own `scopeField` against the subject's scoping
value on a direct (non-`via`) path, the check SHALL be one shared rule: the
list read, the single-object read, the portalAccount lookup behind
`scopeClaim`, and the ownership re-read of the verified update. A stored single
value SHALL match when it is a string or an integer equal to the scoping value.
A stored list SHALL match when at least one element is a string or an integer
equal to the scoping value. The rule SHALL NOT match, and the row SHALL be
dropped as not the subject's, when the scoping value is empty, the stored value
is absent or null, the list is empty, the value is an associative array, or the
value is any other shape. Matching SHALL be strict: no loose comparison, no
substring, no nested list. The tenant check SHALL still apply after a match.

#### Scenario: A list that contains the subject's ref is returned
@e2e exclude {backend scope rule with no UI flow of its own; asserted in tests/Unit/Service/PortalObjectReaderTest.php::testListScopeFieldContainingTheRefIsReturned}

- **GIVEN** a direct collection with `scopeField: "learnerRefs"` AND a row with `learnerRefs: ["other", "learner-1"]`
- **WHEN** the subject whose scoping value is `learner-1` reads the collection
- **THEN** the row SHALL be returned

#### Scenario: A list without the subject's ref is dropped
@e2e exclude {isolation invariant with no UI surface; asserted in tests/Unit/Service/PortalObjectReaderTest.php::testListScopeFieldWithoutTheRefIsDropped}

- **GIVEN** a row with `learnerRefs: ["other", "someone-else"]`
- **WHEN** the subject whose scoping value is `learner-1` reads the collection
- **THEN** the row SHALL NOT be returned

#### Scenario: An empty list is dropped
@e2e exclude {fail-closed invariant with no UI surface; asserted in tests/Unit/Service/PortalObjectReaderTest.php::testEmptyListScopeFieldIsDropped}

- **GIVEN** a row with `learnerRefs: []`
- **WHEN** any subject reads the collection
- **THEN** the row SHALL NOT be returned

#### Scenario: A single value still matches as before
@e2e exclude {unchanged single-value contract; asserted in tests/Unit/Service/PortalObjectReaderTest.php::testSingleValueScopeFieldStillMatches}

- **GIVEN** a direct collection with `scopeField: "subjectRef"` AND rows with `subjectRef: "s1"` and `subjectRef: "s2"`
- **WHEN** subject `s1` reads the collection
- **THEN** only the `s1` row SHALL be returned

#### Scenario: Any other shape fails closed
@e2e exclude {fail-closed invariant with no UI surface; asserted in tests/Unit/Service/PortalObjectReaderTest.php::testOtherScopeShapesFailClosed}

- **GIVEN** rows whose scope field is an associative array containing the ref as a value, a nested list containing the ref, null, or absent
- **WHEN** the subject reads the collection, OR a subject with an empty scoping value reads it
- **THEN** no row SHALL be returned

### Requirement: A write MUST keep a verified list and stamp a list for an array scope field

On the verified update, when the row's stored scope value is a list that the
shared rule matched, the writer SHALL re-stamp the scope field with that stored
list after the merge, so a patch can neither drop the other members nor add
any; a client value for the scope field SHALL never win. On a single stored
value the writer SHALL re-stamp the subject's scoping value exactly as before.
On create, the writer SHALL stamp `[subjectRef]` when the target schema
declares the scope field as `type: array`, and the single `subjectRef`
otherwise, including when the schema cannot be read. Every path that writes
through the verified update (the contribution update, mark-read, status
transitions and the scoped file upload) SHALL inherit this rule.

#### Scenario: An update on a list that contains the ref keeps the list
@e2e exclude {backend write contract with no UI flow of its own; asserted in tests/Unit/Service/PortalObjectWriterTest.php::testUpdateOnAListScopeFieldContainingTheRefKeepsTheList}

- **GIVEN** a row with `learnerRefs: ["learner-1", "learner-2"]`
- **WHEN** subject `learner-1` patches a whitelisted field, and the body also carries `learnerRefs: ["intruder"]`
- **THEN** the save SHALL happen with `learnerRefs: ["learner-1", "learner-2"]`

#### Scenario: An update on a list without the ref is refused before any write
@e2e exclude {write-IDOR invariant with no UI surface; asserted in tests/Unit/Service/PortalObjectWriterTest.php::testUpdateRefusesAListScopeFieldWithoutTheRef}

- **GIVEN** a row with `learnerRefs: ["learner-2"]` or `learnerRefs: []`
- **WHEN** subject `learner-1` patches it
- **THEN** the result SHALL be "not found" AND the OpenRegister save SHALL NOT be called

#### Scenario: A create on an array scope field stamps a one-element list
@e2e exclude {backend write contract with no UI flow of its own; asserted in tests/Unit/Service/PortalObjectWriterTest.php::testCreateStampsAOneElementListForAnArrayScopeField}

- **GIVEN** a create action with `scopeField: "learnerRefs"` on a schema that declares `learnerRefs` as `type: array`
- **WHEN** subject `learner-1` creates an object, and the body also carries `learnerRefs: ["intruder"]`
- **THEN** the object SHALL be saved with `learnerRefs: ["learner-1"]`

#### Scenario: A create on any other scope field stamps the single value
@e2e exclude {unchanged single-value contract; asserted in tests/Unit/Service/PortalObjectWriterTest.php::testCreateStampsTheSingleValueWhenTheSchemaIsNotAnArray}

- **GIVEN** a create action whose schema declares the scope field as `type: string`, or whose schema cannot be read
- **WHEN** subject `s1` creates an object
- **THEN** the object SHALL be saved with the scope field set to `s1`

## MODIFIED Requirements

### Requirement: Scoped single-object read

The reader MUST expose a single-object read that returns ONE object by id,
scoped to the subject by the SAME per-row ownership boundary as the list read.
It MUST resolve the scoping value identically to the list read (a declared
`scopeClaim` → the server-resolved claim from the subject's own portalAccount,
else the subjectRef; an absent or malformed claim MUST fail closed to "not
found" WITHOUT fetching the object). It MUST fetch the object by id, then
re-check ownership: for a direct collection `row[scopeField]` MUST match the
scoping value under the shared direct scope rule (equal for a single value,
strict membership for a list, never on any other shape) and the tenant MUST
match; for a `via` collection the object MUST
pass the one-hop join membership (the identical verified pre-pass, `match`
mode, and tenant discipline as the list read). Field projection, when declared,
MUST run before returning. An object owned by a different subject, in a
different tenant, not a join member, with an absent/malformed claim, or with an
id that does not exist MUST ALL return the identical "not found" result — there
MUST be NO existence oracle. The read MUST fail closed (missing OpenRegister,
OR error, malformed row) to "not found". The controller MUST answer
`GET .../collections/{register}/{schema}/{id}` with the object (200) or 404,
after authorising the collection exactly like the list read (manifest
membership honouring `?collection=`, plus the matched collection's `minTrust`
re-checked — 403 before any OpenRegister call). Added by the
`portal-scoped-crud` change (ADR-062 Phase 1); list membership added by
`portal-scope-list-membership`.

#### Scenario: A subject reads its own object by id

- GIVEN a collection the subject is entitled to AND an object whose `scopeField` equals the subject's scoping value
- WHEN the subject requests that object by id
- THEN the object is returned (200), projected to the collection's `fields` when declared
- @e2e exclude backend single-read contract — covered by the PHPUnit reader/controller matrices; no distinct portaliq UI flow

#### Scenario: A subject reads an object it shares through a list scope field

- GIVEN a direct collection whose `scopeField` is a list AND an object whose list contains the subject's scoping value
- WHEN the subject requests that object by id
- THEN the object is returned (200); an object whose list does not contain the value, or is empty, is 404
- @e2e exclude backend single-read contract; asserted in tests/Unit/Service/PortalObjectReaderTest.php::testReadObjectMatchesAListScopeFieldByMembership

#### Scenario: A foreign-owned or absent id is an identical 404

- GIVEN a subject AND an id that either belongs to a DIFFERENT subject/tenant, is not a join member, or does not exist
- WHEN the subject requests that id
- THEN the response is 404 with the identical body in every case — no existence oracle
- @e2e exclude no-oracle security invariant — covered by PHPUnit (foreign-owner, foreign-tenant, non-member, non-existent) all returning null → 404; no UI surface

### Requirement: Scoped verified update

The writer MUST expose a verified update that patches ONE object by id, and
MUST re-verify ownership against OpenRegister BEFORE any write: it MUST re-read
the row by id and confirm `row[scopeField]` matches the subject's reference
under the shared direct scope rule (equal for a single value, strict membership
for a list, never on any other shape) AND
the tenant matches (the SAME boundary as the reader's per-row check); if the
row is not the subject's — foreign owner, wrong tenant, or non-existent id — it
MUST return "not found" and MUST NOT call the OpenRegister save at all. The
client-supplied id MUST NEVER be trusted as a capability. On an owned row it
MUST merge only the already-whitelisted fields onto the existing object,
re-stamp the scope field (and organisation) AFTER the merge so a patch can
never move the row out of the subject's scope (a verified list is re-stamped
with the stored list itself, a single value with the subject's reference), and
save with the id preserved
so OpenRegister UPDATES rather than creates. The update MUST fail closed (OR
error, missing OpenRegister) to "not found". The controller MUST answer
`PATCH .../collections/{register}/{schema}/{id}` after authorising a declared
`{id, type: 'update', register, schema, fields, minTrust?}` action (403 if
none; the matched action's `minTrust` re-checked before any write) and
whitelisting the request body to the action's `fields` (the scope field is
never whitelisted, and `claims` is always dropped); a null result is 404, no
existence oracle. This closes the write-side IDOR concern
(Conduction/portaliq#16). Added by the `portal-scoped-crud` change; list
membership added by `portal-scope-list-membership`.

#### Scenario: A subject patches its own object

- GIVEN a `type: update` action for a collection the subject is entitled to AND an object the subject owns
- WHEN the subject PATCHes whitelisted fields on that object by id
- THEN only the whitelisted fields change, unrelated fields are preserved, the scope field is re-stamped, and OpenRegister updates the row (id preserved)
- @e2e exclude backend update contract — covered by the PHPUnit writer/controller matrices; no distinct portaliq UI flow

#### Scenario: A patch to a foreign-owned id is refused before any write

- GIVEN a subject AND an id that belongs to a DIFFERENT subject or tenant
- WHEN the subject PATCHes that id
- THEN ownership is re-verified against OpenRegister FIRST, the write is refused (the OpenRegister save is never called), and the response is 404 — closing Conduction/portaliq#16
- @e2e exclude write-IDOR security invariant — pinned by a PHPUnit test asserting the save is never called for a foreign id; no UI surface

## Non-Functional Requirements

- **Performance:** the match is an in-memory check over the rows OpenRegister already returned; no extra query on read or update. The create adds one schema lookup by slug.
- **Accessibility:** no UI change.
- **Internationalization:** no new strings.

## Acceptance Criteria

- A list scope field that contains the subject's scoping value matches on the list read, the single read and the verified update.
- A list without it, an empty list, an associative array, a nested list, null, absent, and an empty scoping value never match.
- A single-value scope field behaves exactly as before.
- The verified update keeps a verified list; the create stamps a one-element list for an `array` scope field.

## Notes

The query-side filter is unchanged: OpenRegister already filters an `array`
property by containment (`MagicSearchHandler::applyJsonArrayFilter`), so the
query returns the right rows and the per-row rule stays the boundary. Schema.org
typing and OCP interfaces are not affected; the change is internal to the scope
check.

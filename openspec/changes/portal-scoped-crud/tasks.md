# Tasks: portal-scoped-crud

> Scoped single-object read + scoped verified update (portaliq side only).
> Checkbox budget: 5 tasks × 2 = 10 unindented `- [ ]` lines (cap 20).
> Acceptance criteria are plain bullets by design — do not convert them.

## Implementation Tasks

### Task 1: Reader scoped single-object read
- **spec_ref**: `openspec/changes/portal-scoped-crud/specs/portal-contribution-contract/spec.md#requirement-scoped-single-object-read`
- **files**: `lib/Service/PortalObjectReader.php`, `tests/Unit/Service/PortalObjectReaderTest.php`
- **acceptance_criteria**:
  - GIVEN `readObject(...)` WHEN it resolves the scope value THEN it uses the SAME rule as `readCollection` (scopeClaim → `resolveClaim`, else subjectRef); an absent/malformed claim fails closed to null WITHOUT the object fetch
  - GIVEN a fetched object WHEN it is a direct collection THEN it passes through `verifyScope` (row[scopeField] === scope value + tenant); WHEN it is a `via` collection THEN it passes the one-hop join membership (same `verifiedJoinTargets`/`filterTargetRows`/`isValidVia` machinery)
  - GIVEN an object owned by a DIFFERENT subject, a foreign tenant, a non-existent id, or an empty id THEN null is returned (→ 404, no existence oracle)
  - GIVEN a verified object THEN `projectRow` is applied before returning; fail-closed on missing OR / OR error → null
- [x] Implement
- [x] Test

### Task 2: Writer scoped verified update (closes #16)
- **spec_ref**: `openspec/changes/portal-scoped-crud/specs/portal-contribution-contract/spec.md#requirement-scoped-verified-update`
- **files**: `lib/Service/PortalObjectWriter.php`, `tests/Unit/Service/PortalObjectWriterTest.php`
- **acceptance_criteria**:
  - GIVEN `updateObject(...)` THEN ownership is re-verified against OpenRegister FIRST — the row is re-read by id and MUST carry `row[scopeField] === subjectRef` + pass the tenant check — and if not the subject's, null is returned and `saveObject` is NEVER called (the write-IDOR pin)
  - GIVEN an owned row THEN the whitelisted `data` is merged onto it, the scope field (+ organisation) is re-stamped AFTER the merge (a sneaked-in scope field is overwritten), and the save preserves the id (`uuid`) so OR UPDATES, not creates
  - GIVEN a non-existent id, an empty id, or an OR write error THEN null is returned (fail-closed); the client-supplied id is never trusted
  - The docblock documents the invariant: ownership re-verified before any write, id never trusted, scope field re-stamped
- [x] Implement
- [x] Test

### Task 3: Controller `object()` + `update()` endpoints
- **spec_ref**: `openspec/changes/portal-scoped-crud/specs/portal-contribution-contract/spec.md#requirement-scoped-single-object-read`
- **files**: `lib/Controller/ContributionController.php`, `tests/Unit/Controller/ContributionControllerTest.php`
- **acceptance_criteria**:
  - `object()`: subject (401) → `authorisedCollection` (403, honouring `?collection=` + the minTrust re-check exactly like `collection()`) → `reader.readObject(...)` with the collection's scopeField/scopeClaim/via/fields → 404 if null (no oracle) → `{object}`
  - `update()`: subject (401) → a `type: update` action for (register, schema) (403 if none) → minTrust re-check → whitelist body to the action's `fields` via the existing `whitelist()` (scope field never whitelisted; `claims` dropped) → `writer.updateObject(...)` → 404 if null → `{object}`; mirrors `create()`'s A6/create authorisation discipline
  - Existing controller suite stays green
- [x] Implement
- [x] Test

### Task 4: Routes + demo provider update action
- **spec_ref**: `openspec/changes/portal-scoped-crud/specs/portal-contribution-contract/spec.md#requirement-scoped-verified-update`
- **files**: `appinfo/routes.php`, `lib/Portal/PortalContributionProvider.php`
- **acceptance_criteria**:
  - GET + PATCH `/portal/api/collections/{register}/{schema}/{id}` routes registered BEFORE the `/portal/{path}` SPA catch-all, with correct `#[PublicPage]` `#[NoCSRFRequired]` under the PortalProtected / PortalAuthMiddleware pattern
  - The demo provider declares ONE `type: update` action (`exampleDocument`, `fields: [title]`) so the patch path is exercisable on a dev install
- [x] Implement
- [x] Test

### Task 5: Vocabulary docs + capability spec maintenance
- **spec_ref**: `openspec/changes/portal-scoped-crud/specs/portal-contribution-contract/spec.md#requirement-scoped-verified-update`
- **files**: `README.md`, `openspec/specs/portal-contribution-contract/spec.md`
- **acceptance_criteria**:
  - README's Portal API table documents the GET-single + PATCH endpoints; the contract vocabulary documents the `type: update` action (ownership re-verified before write, scope re-stamped, id never trusted, no-oracle 404)
  - The main `portal-contribution-contract` spec gains the "Scoped single-object read" + "Scoped verified update" requirements and lists `portal-scoped-crud` under its OpenSpec changes (kept in sync with the delta until archive)
- [x] Implement
- [x] Test

## Quality checklist

- All new/changed logic covered by PHPUnit unit tests (`tests/Unit/`, `phpunit-unit.xml` suite, services constructed directly with a stubbed ObjectService); existing 85-test suite stays green (now 110)
- No new UI surface — no new Playwright surface (scenarios carry reason-bearing `@e2e exclude`); the two new endpoints are API-only
- All tests pass; phpcs, phpstan, psalm green the repo's configured way; fix pre-existing issues encountered in touched files in the same batch (fixed a missing `@param $collectionId` docblock)
- `@spec` tags on every changed method (gate-16); SPDX tags inside the main docblock
- No new user-facing strings — i18n N/A this slice (ADR-007)
- No register/JSON edits expected; `openspec validate` passes

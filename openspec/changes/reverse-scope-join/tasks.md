# Tasks: reverse-scope-join

> Reverse-direction `via.match` on the one-hop join (portaliq side only).
> Checkbox budget: 4 tasks × 2 = 8 unindented `- [ ]` lines (cap 20).
> Acceptance criteria are plain bullets by design — do not convert them.

## Implementation Tasks

### Task 1: Reader reverse `match` mode + fail-closed validation
- **spec_ref**: `openspec/changes/reverse-scope-join/specs/portal-contribution-contract/spec.md#requirement-one-hop-via-join-scoping`
- **files**: `lib/Service/PortalObjectReader.php`, `tests/Unit/Service/PortalObjectReaderTest.php`
- **acceptance_criteria**:
  - GIVEN a `via` with `match: 'scopeField'` WHEN `readViaCollection()` applies the verified target set THEN outer rows are kept iff the value at the collection's own `scopeField` (dot-path) is in the set — scalar equality OR strict element-wise array-contains; the join pre-pass (`verifiedJoinTargets`), row cap, and per-row dot-path verification are UNCHANGED
  - GIVEN `match` absent OR `match: 'id'` THEN the forward path is byte-for-byte unchanged (outer row matched by its OWN `id`/`uuid`)
  - GIVEN an empty verified set THEN zero rows (no outer read); GIVEN an absent/null outer `scopeField` THEN that row is excluded (never a wildcard); the outer per-row tenant check runs in BOTH modes
  - GIVEN `isValidVia()` THEN it still requires `{register, schema, scopeField, targetField}` and rejects a nested `via`; `match` is optional and, when present, MUST be exactly `'id'` or `'scopeField'` — any other value fails the via closed (zero rows + logged warning)
  - Field projection still runs AFTER filtering, so reverse-joined rows are projected
- [x] Implement
- [x] Test

### Task 2: Confirm the controller needs no change
- **spec_ref**: `openspec/changes/reverse-scope-join/specs/portal-contribution-contract/spec.md#requirement-one-hop-via-join-scoping`
- **files**: `lib/Controller/ContributionController.php`
- **acceptance_criteria**:
  - `collection()` already forwards the whole `via` array (so `match` rides along) AND the collection's own `scopeField`, so no controller change is required — verified by re-reading the handler; existing controller suite stays green
- [x] Implement
- [x] Test

### Task 3: Demo provider declares one reverse-join collection
- **spec_ref**: `openspec/changes/reverse-scope-join/specs/portal-contribution-contract/spec.md#requirement-one-hop-via-join-scoping`
- **files**: `lib/Portal/PortalContributionProvider.php`
- **acceptance_criteria**:
  - One demo collection declares a reverse `via` with `match: 'scopeField'` using ONLY existing demo schemas (`portalAccount` → `exampleDocument`) and existing seeds — deliberately self-referential so the reverse code path is exercisable on a dev install; no register/seed edit (a realistic guardian→learner→grades seed belongs to scholiq's own change)
- [x] Implement
- [x] Test

### Task 4: Vocabulary docs + capability spec maintenance
- **spec_ref**: `openspec/changes/reverse-scope-join/specs/portal-contribution-contract/spec.md#requirement-one-hop-via-join-scoping`
- **files**: `README.md`, `openspec/specs/portal-contribution-contract/spec.md`
- **acceptance_criteria**:
  - README's contract vocabulary documents `via.match` (`'id'` forward default vs `'scopeField'` reverse; scalar/array semantics; fail-closed edges)
  - The main `portal-contribution-contract` spec's "One-hop via join scoping" requirement is updated for reverse `match` and lists `reverse-scope-join` under its OpenSpec changes (kept in sync with the delta until archive)
- [x] Implement
- [x] Test

## Quality checklist

- All new/changed logic covered by PHPUnit unit tests (`tests/Unit/`, `phpunit-unit.xml` suite, reader constructed directly with a stubbed ObjectService); existing 75-test suite stays green (now 83)
- No new/changed API endpoints — Newman/Postman unchanged; no UI change — no new Playwright surface (scenarios carry reason-bearing `@e2e exclude`)
- All tests pass; phpcs, phpstan, psalm green the repo's configured way; fix pre-existing issues encountered in touched files in the same batch
- `@spec` tags on every changed method (gate-16); SPDX tags inside the main docblock
- No new user-facing strings — i18n N/A this slice (ADR-007)
- No register/JSON edits expected; `openspec validate` passes

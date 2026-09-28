# Tasks: portal-scope-list-membership

## Implementation Tasks

### Task 1: One scope rule for reads
- **spec_ref**: `openspec/changes/portal-scope-list-membership/specs/portal-contribution-contract/spec.md#requirement-a-direct-scope-field-must-match-a-single-value-or-strict-list-membership`
- **files**: `lib/Service/PortalScopeMatch.php`, `lib/Service/PortalObjectReader.php`, `tests/Unit/Service/PortalObjectReaderTest.php`
- **acceptance_criteria**:
  - GIVEN a row with `learnerRefs: ["other", "learner-1"]` WHEN subject `learner-1` reads the collection THEN the row is returned
  - GIVEN a row whose list lacks the ref, or is empty WHEN the subject reads THEN the row is dropped
  - GIVEN a single-value scope field WHEN the subject reads THEN the match is exactly as before
  - GIVEN an associative array, a nested list, null, absent, or an empty scoping value WHEN the subject reads THEN nothing is returned
  - GIVEN a single-object read on a list scope field WHEN the list contains the ref THEN the object is returned, else null
- [x] Implement
- [x] Test

### Task 2: The same rule for writes, a kept list on update, a list stamp on create
- **spec_ref**: `openspec/changes/portal-scope-list-membership/specs/portal-contribution-contract/spec.md#requirement-a-write-must-keep-a-verified-list-and-stamp-a-list-for-an-array-scope-field`
- **files**: `lib/Service/PortalObjectWriter.php`, `tests/Unit/Service/PortalObjectWriterTest.php`
- **acceptance_criteria**:
  - GIVEN a row with `learnerRefs: ["learner-1", "learner-2"]` WHEN `learner-1` patches it with `learnerRefs: ["intruder"]` in the body THEN the save keeps `["learner-1", "learner-2"]`
  - GIVEN a row whose list lacks the ref, or is empty WHEN the subject patches it THEN null and no save
  - GIVEN a single-value scope field WHEN the subject patches its own row THEN the scope is re-stamped as before
  - GIVEN a schema declaring the scope field `type: array` WHEN the subject creates THEN the stamp is `[subjectRef]`
  - GIVEN a `type: string` scope field or an unreadable schema WHEN the subject creates THEN the stamp is the single `subjectRef`
- [x] Implement
- [x] Test

### Task 3: Main spec, docs and the upload note
- **spec_ref**: `openspec/changes/portal-scope-list-membership/specs/portal-contribution-contract/spec.md#requirement-a-write-must-keep-a-verified-list-and-stamp-a-list-for-an-array-scope-field`
- **files**: `openspec/specs/portal-contribution-contract/spec.md`, `README.md`
- **acceptance_criteria**:
  - GIVEN the main contract spec WHEN a reader opens it THEN this change is listed under OpenSpec changes
  - GIVEN the README's contract v2 section WHEN an integrator reads it THEN it says a scope field may be a list and how it matches
  - GIVEN PR 745's upload endpoint WHEN it is read THEN the PR body says it needs no edit, because it proves ownership through `readObject` and writes through `updateObject`
- [x] Implement
- [x] Test

## Quality checklist

- All new and changed logic covered by PHPUnit unit tests (`tests/Unit/Service/`)
- No new or changed API endpoints, so no Newman change
- No UI change, so no Playwright change
- No new user-facing strings, so no l10n change
- `openspec validate` passes

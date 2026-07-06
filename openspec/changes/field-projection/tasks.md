# Tasks: field-projection

> Read-side field projection + assertion wire-format pin (portaliq side only).
> Checkbox budget: 5 tasks × 2 = 10 unindented `- [ ]` lines (cap 20).
> Acceptance criteria are plain bullets by design — do not convert them.

## Implementation Tasks

### Task 1: Reader projection primitive + wiring on every read path
- **spec_ref**: `openspec/changes/field-projection/specs/portal-contribution-contract/spec.md#requirement-read-side-field-projection`
- **files**: `lib/Service/PortalObjectReader.php`, `tests/Unit/Service/PortalObjectReaderTest.php`
- **acceptance_criteria**:
  - GIVEN a collection declaring `fields` WHEN `readCollection()` returns (direct AND `via` paths) THEN each row contains only the declared top-level properties that exist on the row plus its identifier(s) — projection applied AFTER per-row verification, never influencing row selection
  - GIVEN flat `id`/`uuid` or an `@self` envelope WHEN projecting THEN flat identifiers survive and `@self` reduces to its `id`/`uuid` members (full `@self` only when explicitly declared)
  - GIVEN `fields` absent (`null`) THEN the full row returns unchanged; GIVEN a malformed declaration (non-array / non-string entries) THEN rows project to identifiers-only (fail-closed narrow); unknown declared fields are silently absent; `scopeField` is not auto-included
  - `projectRow()` is public so a future single-object/detail read reuses identical semantics; unit-tested directly (detail case)
- [x] Implement
- [x] Test

### Task 2: Controller passes the declared fields through (incl. inbox kind)
- **spec_ref**: `openspec/changes/field-projection/specs/portal-contribution-contract/spec.md#requirement-read-side-field-projection`
- **files**: `lib/Controller/ContributionController.php`, `tests/Unit/Controller/ContributionControllerTest.php`
- **acceptance_criteria**:
  - GIVEN `collection()` matches an authorised collection WHEN it calls the reader THEN it forwards `($collection['fields'] ?? null)` unmodified — `null` means no projection
  - GIVEN a `kind: "inbox"` collection declaring `fields` WHEN read through the same endpoint THEN the declaration reaches the reader exactly like any other collection
- [x] Implement
- [x] Test

### Task 3: Demo provider declares fields on one collection
- **spec_ref**: `openspec/changes/field-projection/specs/portal-contribution-contract/spec.md#requirement-read-side-field-projection`
- **files**: `lib/Portal/PortalContributionProvider.php`
- **acceptance_criteria**:
  - The demo `exampleCollection` declares `fields: ["title", "status"]` so a dev install demonstrates projection (rows created via the existing `createExample` action show `title`/`status` + identifier; `subjectRef`/`organisation` absent); other demo collections stay undeclared as the backward-compat reference
  - No register/seed edit (design.md Seed Data — existing nil-UUID seeds unchanged)
- [x] Implement
- [x] Test

### Task 4: Assertion wire-format pin (independent hardening)
- **spec_ref**: `openspec/changes/field-projection/specs/portal-contribution-contract/spec.md#requirement-frozen-assertion-wire-format`
- **files**: `tests/Unit/Service/PortalJwtServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a freshly minted assertion WHEN the test decodes it THEN it asserts the header is exactly `{"alg": "HS256", "typ": "JWT"}`, the claim keys are exactly `sub, audience, organisation, trust, jti, use, iat, exp, iss` (in that order), `use` is the literal `"assertion"`, `iss` the literal `"portaliq"`, every subject value round-trips, and `exp - iat` equals 60 — literals, not class constants
- [x] Implement
- [x] Test

### Task 5: Vocabulary docs + capability spec maintenance
- **spec_ref**: `openspec/changes/field-projection/specs/portal-contribution-contract/spec.md#requirement-read-side-field-projection`
- **files**: `README.md`, `openspec/specs/portal-contribution-contract/spec.md`
- **acceptance_criteria**:
  - README's contract vocabulary section documents `fields` (whitelist semantics, identifier preservation, fail-closed edges, inbox applicability, backward-compat default)
  - The main `portal-contribution-contract` spec gains the two new requirements and lists `field-projection` under its OpenSpec changes (kept in sync with the delta until archive, per the spec's own note)
- [x] Implement
- [x] Test

## Quality checklist

- All new/changed logic covered by PHPUnit unit tests (`tests/Unit/`, `phpunit-unit.xml` suite, classes constructed directly); existing 67-test suite stays green
- No new/changed API endpoints — Newman/Postman unchanged; no UI change — no new Playwright surface (scenarios carry reason-bearing `@e2e exclude`)
- All tests pass; phpcs, phpstan, psalm green the repo's configured way; fix pre-existing issues encountered in touched files in the same batch
- `@spec` tags on every changed method (gate-16); SPDX tags inside the main docblock
- No new user-facing strings — i18n N/A this slice (ADR-007)
- No register/JSON edits expected; `openspec validate` passes

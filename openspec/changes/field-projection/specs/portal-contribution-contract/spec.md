# portal-contribution-contract Delta: field-projection

**Status**: in-progress
**Scope**: portaliq
**OpenSpec changes**:

- [field-projection](../../)

## Purpose

Adds read-side field projection to the contribution contract enforced by
portaliq: a collection may declare which row properties the portal returns,
so contributors with staff-only fields (pipelinq `booking.internalNotes`,
`contactmoment.notes`) can contribute a collection partially instead of
fail-closed excluding it entirely. Also freezes the A6 `X-Portal-Subject`
assertion wire format for receiver-side verifiers. Extends the
`portal-contribution-contract` capability created by `contract-v2`.
Related: ADR-046 (contract), ADR-005 (fail-closed security).

## ADDED Requirements

### Requirement: Read-side field projection

The portal read path MUST support an optional `fields: [string, ...]`
member on a collection declaration — a whitelist of top-level row property
names. Any collection `kind` (including `inbox`) may declare it. When
`fields` is declared, every returned row MUST contain ONLY: the declared
properties that exist on the row, plus
the row identifier(s) — the flat `id` and `uuid` properties when present,
and, when the row carries an `@self` envelope, a reduced `@self` containing
only its `id`/`uuid` members. Projection MUST be applied AFTER per-row
verification and BEFORE the rows are returned, on every read path (direct,
`via`-joined, and any single-object/detail read the reader gains later), and
MUST NOT influence which rows are returned. A declared field that does not
exist on a row MUST simply be absent from the output (pure whitelist — no
error). `scopeField` values MUST NOT be included unless declared. When
`fields` is absent, the full row MUST be returned unchanged (backward
compatible); when `fields` is present but malformed (not a list of
non-empty strings), the row MUST project to identifiers-only — a declared
projection intent never fails open to the full row (ADR-005).

#### Scenario: A collection declares fields and rows are projected

- GIVEN a collection declaring `fields: ["title", "status"]` over rows that also carry `subjectRef`, `organisation`, and `internalNotes`
- WHEN the subject reads the collection
- THEN each returned row contains only `title`, `status`, and the row identifier(s)
- AND `internalNotes` and the `scopeField` value (`subjectRef`) are absent
- @e2e exclude backend row-shaping contract — covered by the PHPUnit projection matrix; the SPA renders whatever properties arrive, no distinct UI flow

#### Scenario: The row identifier is never stripped

- GIVEN a collection declaring `fields: ["title"]` over rows carrying flat `id`/`uuid` or an `@self` envelope
- WHEN the subject reads the collection
- THEN each returned row retains its flat `id`/`uuid` and, when only the envelope carries them, a reduced `@self` with only `id`/`uuid`
- AND detail links built from `id`/`uuid` keep resolving
- @e2e exclude identifier-preservation invariant — pinned by a dedicated PHPUnit test; no portaliq detail UI ships in this change

#### Scenario: Unknown declared fields project to absent

- GIVEN a collection declaring `fields: ["title", "notAProperty"]`
- WHEN the subject reads the collection
- THEN rows contain `title` (plus identifiers) and no `notAProperty` key
- AND the response is 200 — a stale declaration never becomes an error
- @e2e exclude tolerant-whitelist contract — covered by PHPUnit, indistinguishable from a normal read in the UI

#### Scenario: No fields declaration keeps full rows

- GIVEN a collection without a `fields` declaration
- WHEN the subject reads the collection
- THEN rows are returned exactly as before this change (full verified rows)
- @e2e exclude backward-compatibility contract — covered by the existing reader suite kept green plus an explicit full-row PHPUnit case

#### Scenario: A malformed fields declaration fails closed to identifiers-only

- GIVEN a collection whose `fields` is declared but malformed (e.g. a string, or a list of non-strings)
- WHEN the subject reads the collection
- THEN rows contain only their identifier(s) — never the full row
- @e2e exclude fail-closed narrowing — covered by PHPUnit, no UI surface for a malformed manifest

#### Scenario: An inbox collection may declare fields

- GIVEN a `kind: "inbox"` collection declaring `fields: ["subject", "read"]`
- WHEN the subject reads it through the same collection endpoint
- THEN message rows are projected exactly like any other collection (declared fields + identifiers; `body` absent)
- @e2e exclude same code path as list projection — covered by a PHPUnit controller pass-through case; inbox rendering itself is unchanged

### Requirement: Frozen assertion wire format

The A6 `X-Portal-Subject` assertion wire format MUST be treated as frozen
for receiver-side verifiers: header exactly `{"alg": "HS256", "typ": "JWT"}`
and the exact claim set `sub`, `audience`, `organisation`, `trust`, `jti`,
`use` (literal `"assertion"`), `iat`, `exp`, `iss` (literal `"portaliq"`),
with `exp - iat` equal to the 60-second assertion TTL. A unit test MUST pin
every element of that shape so any drift fails loudly before it can break
domain-app verifiers templated against it.

#### Scenario: The assertion shape is pinned

- GIVEN a freshly minted `X-Portal-Subject` assertion
- WHEN its header and claims are decoded
- THEN the header is exactly `{"alg": "HS256", "typ": "JWT"}` and the claim keys are exactly `sub`, `audience`, `organisation`, `trust`, `jti`, `use`, `iat`, `exp`, `iss` with `use = "assertion"`, `iss = "portaliq"`, and `exp - iat = 60`
- @e2e exclude wire-format pin — a PHPUnit compatibility test by definition; no UI or HTTP surface

## Non-Functional Requirements

- **Performance:** projection is a single in-memory pass over the already
  row-capped result set (≤ 200 list rows / ≤ 500 join rows); no additional
  OpenRegister queries.
- **Security (ADR-005):** projection is output shaping on top of — never a
  replacement for — per-row verification; every malformed edge narrows the
  output (identifiers-only), never widens it.
- **Accessibility / i18n:** no UI change and no new user-facing strings in
  this slice.

## Acceptance Criteria

- Rows of a `fields`-declaring collection contain only declared properties plus identifiers, on both direct and `via` read paths
- Flat `id`/`uuid` and reduced-`@self` identifiers survive every projection; unknown declared fields are silently absent
- Absent `fields` → full rows (existing 67-test suite green); malformed `fields` → identifiers-only
- `kind: "inbox"` collections project through the same path
- The assertion pin test asserts header alg and every claim explicitly
- README documents `fields` in the contract vocabulary section

## Notes

- The reader exposes projection as a public single-row primitive
  (`projectRow()`) so a future single-object/detail read reuses the exact
  same semantics — the requirement already binds such a path.
- Canonical contract text remains the ADR-046 amendment (hydra); `fields`
  is portaliq-enforced vocabulary in the same spirit as `minTrust` /
  `scopeClaim` / `via` — declarative, optional, v1-compatible default.

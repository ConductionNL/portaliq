# Design: field-projection

## Context

Contract v2 (merged) reads collections through `PortalObjectReader`: query
OpenRegister with `_rbac: false` / `_multitenancy: false`, then per-row
verification (`verifyScope()` on the direct path, join-membership +
organisation checks on the `via` path) is the security boundary. Rows that
survive are returned **whole**. Wave-1 contributor reviews showed that whole
rows are the blocker: pipelinq `booking.internalNotes` and
`contactmoment.notes` are staff-only, so those collections were fail-closed
excluded from the portal entirely. The fix belongs in the hub's one read
path, declaratively, so contributors never write portal code.

In parallel, domain apps are templating receiver-side `X-Portal-Subject`
verifiers (petstore) against the assertion portaliq mints; nothing currently
pins that wire format.

## Goals / Non-Goals

**Goals:** declarative per-collection read projection; identifier-preserving
output so detail links keep working; fail-closed edges; zero behaviour
change for collections without `fields`; a frozen assertion wire format.

**Non-Goals:** write-side shaping (create whitelist exists), nested/dot-path
projection, register/schema changes, SPA changes, receiver-side verifier
code.

## Decisions

### D1: Projection lives in the reader, after verification

`readCollection()` applies projection as the LAST step on both return paths
(direct: after `verifyScope()`; joined: after target filtering in
`readViaCollection()`). Alternative considered: projecting in the controller
— rejected because any future caller of the reader (detail reads, MCP
surface) would silently get unprojected rows; the reader is the single
choke point, exactly like verification. Projection never touches row
*selection*: a foreign row is dropped by verification whether or not
`fields` is declared.

### D2: Identifier handling — flat `id`/`uuid` + reduced `@self`

The projected row always retains, regardless of the declaration:

- flat `id` and `uuid` properties when present on the row, and
- when the row carries an `@self` envelope (OpenRegister's metadata
  envelope) and `@self` itself is not declared, a **reduced** `@self`
  containing only its `id`/`uuid` members.

Rationale: the portal SPA builds detail keys from `o.id || o['@self']?.id`,
and `rowIds()` (join membership) already treats exactly these four
locations as "the identifier". Passing the full `@self` through would leak
envelope metadata (owner, organisation, register internals) that projection
exists to suppress — hence the reduction. A contributor who wants the full
envelope can declare `"@self"` explicitly (pure whitelist semantics).

### D3: Fail-closed edge semantics

- `fields` **absent** (`null`) → full row. Only genuine absence keeps the v1
  behaviour.
- `fields` declared but **malformed** (not an array) → identifiers-only.
  A declaration signals intent to hide fields; failing open to the full row
  on a typo would leak exactly what the contributor tried to hide
  (ADR-005). Non-string / empty entries inside a declared list are ignored
  the same way.
- **Unknown declared field** → absent from output, no error: a stale
  manifest must not break the portal page (mirrors the absent-claim
  posture: degrade, don't error).
- `scopeField` is NOT auto-included — the scoping value is often the very
  pseudonym/claim there is no reason to echo back.
- Field names match **top-level row keys** exactly; no dot-path
  interpretation (nested shaping is out of scope; a dot in a name is just
  an unknown key).

### D4: Detail/single-object reads

The reader has no single-object read today. Projection is factored as a
public single-row primitive — `projectRow(array $row, mixed $fields)` —
with `projectRows()` looping it, so a future detail read applies identical
semantics by construction; the spec requirement already binds "any
single-object/detail read". The unit suite pins the single-row (detail)
semantics directly against `projectRow()`.

### D5: Assertion pin as a wire-format test

The pin test decodes the compact JWT itself (header segment + validated
claims) and asserts literals — `HS256`, `assertion`, `portaliq`, the exact
ordered claim-key list, `exp - iat = 60` — deliberately NOT the class
constants, so a "harmless" constant rename or claim addition fails the
test. Receiver templates may rely on: header `{"alg": "HS256", "typ":
"JWT"}`; claims exactly `sub, audience, organisation, trust, jti, use,
iat, exp, iss`.

## Declarative-vs-imperative note

Same posture as contract-v2: ADR-031's declarative default governs domain
apps' business logic; the *contract itself* is the declarative surface here
— a contributor expresses projection as manifest data (`fields: [...]`),
writing no portal code. The enforcement of that declaration is hub
infrastructure on a security boundary (it decides what leaves the trusted
intermediary), so it stays imperative, unit-testable reader code alongside
verification — not an OpenRegister schema dialect, which acts on layers
where portal subjects do not exist.

## Risks / Trade-offs

- [Projection mistaken for authorisation] → applied strictly after
  verification; tests assert foreign rows still drop when `fields` is
  declared; comments state it shapes output, not access.
- [Reduced `@self` surprises a consumer expecting the full envelope] →
  whitelist escape hatch (`"@self"` declarable); documented in README.
- [Per-row whitelist normalisation cost] → negligible at the existing row
  caps (≤ 200/500 rows, ≤ dozens of fields); no extra queries.
- [Pinned claim-key ORDER is stricter than JSON requires] → intentional:
  order changes signal serializer changes worth reviewing; relaxing the pin
  is a one-line conscious edit.

## Seed Data

**No new seed objects; no register edit.** Every existing seed keeps using
nil UUIDs / obvious placeholders only (contract-v2 design.md Seed Data —
`dev-supplier` / `dev-client` / `00000000-0000-0000-0000-000000000000`).

The demo stays exercisable without new seeds: the demo provider's
`exampleCollection` (register `portaliq`, schema `exampleDocument`) now
declares `fields: ["title", "status"]`, and its existing `createExample`
action creates `exampleDocument` rows on a dev install — the seeded
`dev-supplier` account then sees created rows projected to
`title`/`status` (+ identifier), with `subjectRef`/`organisation`
demonstrably absent. The claimless `dev-client` seed continues to prove the
unrelated fail-closed-empty path.

## Migration Plan

Deploy: merge; no schema, register, data, or route change — behaviour
changes only for collections that declare `fields` (none exist in the fleet
yet except the demo). Rollback: revert the PR; the optional parameter
defaults to `null`, so full-row behaviour returns everywhere instantly.

## Open Questions

None.

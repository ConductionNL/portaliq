# portal-contribution-contract Specification

**Status**: in-progress
**Scope**: portaliq
**OpenSpec changes**:

- [contract-v2](../../changes/contract-v2/)
- [field-projection](../../changes/field-projection/)
- [reverse-scope-join](../../changes/reverse-scope-join/)

## Purpose

Defines contribution contract v2 as enforced by portaliq (the hub side of the
ADR-046 amendment, 2026-07-06): how the registry discovers multi-audience
providers, how trust levels gate what a subject sees and may do, how a
collection selects its scoping value (subjectRef, a server-managed claim, or a
one-hop `via` join in either direction — forward by outer id or reverse by the
outer `scopeField` value), how a collection projects verified rows down to a
declared `fields` whitelist, and how declared endpoint actions are forwarded
server-to-server with a signed subject assertion. Contract v1 (single
audience, subjectRef-only scoping, create-only actions) was proven live by the
`supplier-portal` change; every v2 field is optional with v1-equivalent
defaults. Related: ADR-046 (canonical contract text, hydra), ADR-005
(fail-closed security), ADR-022 (reads via OpenRegister, never via the domain
app).

## Requirements

### Requirement: Multi-audience provider discovery

`PortalContributionRegistry` MUST prefer a duck-typed `getAudiences(): array`
on a discovered provider when that method exists, and MUST fall back to
`getAudience(): string` otherwise (v1 compatibility). A provider MUST be
consulted exactly when the subject's audience is contained in the provider's
audience list; `getContribution(array $subject)` receives the subject and
branches on `$subject['audience']`. The audience vocabulary is an open string
set — the registry MUST NOT restrict it to an enum.

#### Scenario: A multi-audience provider serves two audiences

- GIVEN a provider exposing `getAudiences()` returning `["client", "supplier"]`
- WHEN contributions are aggregated for a subject with audience `supplier`
- THEN the provider is consulted and its contribution appears in the manifest
- AND aggregating for a subject with audience `client` also consults it
- @e2e exclude backend registry contract — covered by PHPUnit aggregation matrix, no distinct UI flow

#### Scenario: A v1 single-audience provider keeps working

- GIVEN a provider exposing only `getAudience()` returning `supplier`
- WHEN contributions are aggregated for a `supplier` subject
- THEN the provider is consulted exactly as under contract v1
- AND aggregating for a `client` subject does not consult it
- @e2e exclude backward-compatibility contract — covered by the existing PHPUnit registry suite kept green

### Requirement: Trust ordering and manifest filtering

Portaliq MUST order trust levels `low < substantial < high`. A collection or
action MAY declare `minTrust`; a missing `minTrust` MUST default to `low`. A
missing or unrecognised subject `trust` value MUST be treated as `low`
(fail-closed), and an unrecognised `minTrust` value MUST render the entry
unsatisfiable (dropped for every subject). The aggregated manifest returned to
the subject MUST exclude every collection and action whose `minTrust` exceeds
the subject's trust.

#### Scenario: A low-trust subject does not see substantial-trust entries

- GIVEN a contribution declaring a collection with `minTrust: substantial` and an action with `minTrust: high`
- WHEN a subject whose session carries `trust: low` fetches `/portal/api/contributions`
- THEN neither the collection nor the action appears in the returned manifest
- AND a subject with `trust: high` sees both
- @e2e exclude backend filtering contract — covered by PHPUnit trust matrix; SPA renders whatever the manifest returns

#### Scenario: Unknown trust values fail closed

- GIVEN a session minted with a legacy or unknown trust string (for example `dev` or `EH3`)
- WHEN the manifest is aggregated
- THEN the subject is treated as `trust: low`
- AND entries with an unrecognised `minTrust` value are excluded for every subject
- @e2e exclude fail-closed normalisation — covered by PHPUnit, not a UI flow

### Requirement: Server-side trust enforcement on read, create, and action

Portaliq MUST enforce `minTrust` server-side and fail closed on every data
path — collection read, object create, and endpoint-action forward — so a
client crafting a direct request cannot bypass the manifest filter. A request
against an entry whose `minTrust` exceeds the subject's trust MUST be rejected
with 403 and MUST NOT touch OpenRegister or the domain app.

#### Scenario: Direct read below the trust threshold is rejected

- GIVEN a collection declared with `minTrust: substantial`
- WHEN a subject with `trust: low` calls `GET /portal/api/collections/{register}/{schema}` directly
- THEN the response is 403 and no OpenRegister query is issued
- AND the same subject calling the create or action endpoint for a `minTrust: substantial` entry also receives 403
- @e2e exclude authorization contract — covered by PHPUnit fail-closed matrix and Newman 403 contract; the UI only offers entries the manifest contains

### Requirement: Server-managed claim map and scopeClaim scoping

The `portalAccount` schema MUST carry a server-managed `claims` object
property shaped `{appId: {claimName: uuid}}` (generalising pipelinq's
`linkedContactId`). A collection MAY declare `scopeClaim` addressing one claim
— either `"appId.claimName"` (explicit) or a bare `"claimName"` (resolved in
the contributing app's own namespace). When `scopeClaim` is declared, the
reader MUST resolve the scoping value server-side from the subject's own
`portalAccount` object and use it against the collection's `scopeField`;
without `scopeClaim` the scoping value remains the subject's `subjectRef`.
Claims MUST never be read from client input, and a client-supplied `claims`
field MUST never reach an OpenRegister write. If the addressed claim is absent
for the subject, the collection MUST contribute zero rows (fail-closed empty,
not an error).

#### Scenario: A collection scopes by a linked-contact claim

- GIVEN a subject whose `portalAccount.claims` contains `{"pipelinq": {"linkedContactId": "00000000-0000-0000-0000-000000000000"}}`
- AND a pipelinq collection declaring `scopeClaim: "linkedContactId"` with `scopeField: "contact"`
- WHEN the subject reads that collection
- THEN only rows whose `contact` equals `00000000-0000-0000-0000-000000000000` are returned, each re-verified per row
- @e2e exclude backend scoping contract — covered by PHPUnit claim-resolution suite; requires seeded claims, no distinct UI surface

#### Scenario: An absent claim yields an empty collection, not an error

- GIVEN a subject whose `portalAccount` has no claim for the addressed `appId.claimName`
- WHEN the subject reads a collection declaring that `scopeClaim`
- THEN the response is 200 with zero objects
- AND no unscoped OpenRegister query is issued
- @e2e exclude fail-closed-empty contract — covered by PHPUnit, indistinguishable from an empty collection in the UI

### Requirement: One-hop via join scoping

The reader MUST support one declared join per collection —
`via: {register, schema, scopeField, targetField, match?}` (optional). It MUST
first resolve join rows in `via.register`/`via.schema` whose `via.scopeField`
(dot-path allowed for nested properties) equals the collection's scoping value,
per-row verified; MUST collect the `targetField` references from the verified
join rows into a set; and MUST apply that set to the outer rows the way the
optional `match` discriminator selects:

- **`match: 'id'`** (the DEFAULT when absent) — *forward*: return only outer
  objects whose own `id`/`uuid` is in the set, verified per row. This is the
  original A5 behaviour, unchanged.
- **`match: 'scopeField'`** — *reverse*: return only outer rows whose value at
  the collection's own `scopeField` (dot-path allowed) is in the set — scalar
  equality, OR strict element-wise membership for a multi-value field (ANY
  element in the set matches; no loose comparison). An outer row whose
  `scopeField` value is absent or null MUST be excluded (never treated as a
  wildcard).

The join pre-pass (per-row dot-path verification, the row cap, and the tenant
check) MUST be identical in both directions and is the security boundary; the
per-row organisation verification MUST also be applied to the outer rows in
both directions. `match`, when present, MUST be exactly `'id'` or
`'scopeField'` — any other value MUST fail the whole `via` closed (zero rows +
logged warning), exactly like a structurally invalid `via`. An empty verified
join set MUST yield zero rows in BOTH modes (fail-closed empty, never all
rows). Exactly one hop is supported: a `via` declaration nested inside another
`via`, or a structurally invalid `via`, MUST yield zero rows. The join pre-pass
and outer read MUST apply the same `_rbac: false` / `_multitenancy: false` +
per-row organisation verification discipline as direct reads. Field projection,
when declared, MUST run AFTER this filtering in both modes.

#### Scenario: A case is readable because a role row links the subject

- GIVEN join rows in `zaken`/`rol` where `betrokkeneIdentificatie.inpBsn` equals the subject's scoping value and `zaak` references target UUIDs
- AND a collection on `zaken`/`zaak` declaring that `via` (no `match`, or `match: "id"`)
- WHEN the subject reads the collection
- THEN only `zaak` objects whose `id`/`uuid` appears in the verified join set are returned
- AND a `zaak` not referenced by any of the subject's join rows is never returned even if OpenRegister returns it
- @e2e exclude backend join contract — covered by PHPUnit forward-via suite (join match, target membership, foreign-row drop) plus an explicit `match:"id"` ≡ absent pin; no portaliq UI change

#### Scenario: A guardian reads grades via a reverse scopeField join

- GIVEN join rows in `scholiq`/`learnerProfile` where `guardianRefs` contains the subject's scoping value and `learnerRef` references the guardian's children
- AND a collection on `scholiq`/`gradeEntry` with `scopeField: "learnerRef"` declaring that `via` with `match: "scopeField"`
- WHEN the subject reads the collection
- THEN only `gradeEntry` rows whose own `learnerRef` is one of the verified children is returned, each re-checked per row and by tenant
- AND a grade for a child the subject does not guardian is never returned even if OpenRegister returns it
- @e2e exclude backend reverse-join contract — covered by the PHPUnit reverse-match matrix (scalar + array-element match, foreign-row drop); requires scholiq schemas, no distinct portaliq UI flow

#### Scenario: A multi-value scopeField matches on any element

- GIVEN a reverse (`match: "scopeField"`) collection whose outer rows carry a multi-value `scopeField` (e.g. `learnerRefs: [...]`)
- WHEN the subject reads the collection
- THEN a row is returned iff AT LEAST ONE element of its `scopeField` is in the verified set (strict, element-wise), and excluded when none is
- @e2e exclude strict-membership invariant — pinned by a dedicated PHPUnit case; no UI surface

#### Scenario: Reverse match never widens

- GIVEN a reverse (`match: "scopeField"`) collection AND a subject whose verified join set is empty, OR outer rows whose `scopeField` is absent/null
- WHEN the subject reads the collection
- THEN the response is 200 with zero rows — an empty set skips the outer read entirely, and an absent/null `scopeField` value is excluded (never a wildcard)
- @e2e exclude fail-closed-empty contract — covered by PHPUnit, indistinguishable from an empty collection in the UI

#### Scenario: More than one hop, or a malformed match, fails closed

- GIVEN a collection whose `via` is structurally invalid, attempts a nested join, or carries a `match` value other than `"id"`/`"scopeField"`
- WHEN the subject reads the collection
- THEN the response is 200 with zero objects and a warning is logged
- @e2e exclude defensive validation — covered by PHPUnit, no UI surface

### Requirement: Endpoint bearer-forward actions

Portaliq MUST support actions declared as `{id, label, endpoint, method,
minTrust?}` and MUST expose `POST /portal/api/actions/{appId}/{actionId}` which:
fails closed 401 without a valid bearer (PortalAuthMiddleware); returns 403
unless an action with that id, a non-empty `endpoint`, and an allowed `method`
exists in the **subject's own aggregated manifest** for that app (same
authorisation pattern as the existing create path) and its `minTrust` is
satisfied; forwards the request server-to-server (`OCP\Http\Client\IClientService`)
to the declared endpoint with the declared method, attaching a short-lived
(~60 seconds) HS256-signed `X-Portal-Subject` JWT assertion carrying
`subjectRef`, `audience`, `organisation`, `trust`, and `jti`, signed with the
instance secret via the existing `PortalJwtService` secret sourcing; and
relays the domain app's response status and JSON body to the caller. The
subject identity forwarded MUST come only from the validated session, never
from client input.

#### Scenario: A declared action is forwarded with a signed assertion

- GIVEN a contribution declaring action `{id: "requestRenewal", endpoint: "/apps/EXAMPLE_APP/api/portal/renewals", method: "POST"}`
- WHEN an authenticated, sufficiently trusted subject calls `POST /portal/api/actions/EXAMPLE_APP/requestRenewal`
- THEN portaliq calls the declared endpoint server-to-server with an `X-Portal-Subject` HS256 assertion (TTL ≈ 60s) carrying the session's subjectRef/audience/organisation/trust/jti
- AND the domain app's response status and body are relayed to the caller
- @e2e exclude server-to-server forward — covered by PHPUnit (authorisation, assertion claims/TTL, relay) with a stubbed HTTP client; no receiving app ships in this change

#### Scenario: An action outside the subject's manifest is rejected

- GIVEN an `{appId, actionId}` pair that does not appear in the subject's own aggregated manifest (unknown id, missing endpoint, or unmet `minTrust`)
- WHEN the subject calls the action endpoint
- THEN the response is 403 and no server-to-server request is made
- @e2e exclude authorization contract — covered by PHPUnit fail-closed matrix and Newman 403 contract

### Requirement: Subject assertions are not portal sessions

The `X-Portal-Subject` assertion MUST be distinguishable from a portal session
token (a dedicated claim marks it as an assertion), and
`PortalSessionService::resolveFromBearer` MUST reject an assertion presented
as an `Authorization` bearer — a relayed or leaked assertion can never be
replayed as a portal session (ADR-005).

#### Scenario: An assertion presented as a bearer fails closed

- GIVEN a freshly minted, unexpired `X-Portal-Subject` assertion
- WHEN it is presented as `Authorization: Bearer <ASSERTION_JWT_HERE>` to any protected portal endpoint
- THEN the request is rejected with 401
- @e2e exclude token-confusion guard — covered by PHPUnit fail-closed test, not a UI flow

### Requirement: Read-side field projection

The portal read path MUST support an optional `fields: [string, ...]`
member on a collection declaration — a whitelist of top-level row property
names. Any collection `kind` (including `inbox`) may declare it. When
`fields` is declared, every returned row MUST contain ONLY: the declared
properties that exist on the row, plus the row identifier(s) — the flat `id`
and `uuid` properties when present, and, when the row carries an `@self`
envelope, a reduced `@self` containing only its `id`/`uuid` members.
Projection MUST be applied AFTER per-row verification and BEFORE the rows
are returned, on every read path (direct, `via`-joined, and any
single-object/detail read the reader gains later), and MUST NOT influence
which rows are returned. A declared field that does not exist on a row MUST
simply be absent from the output (pure whitelist — no error). `scopeField`
values MUST NOT be included unless declared. When `fields` is absent, the
full row MUST be returned unchanged (backward compatible); when `fields` is
present but malformed (not a list of non-empty strings), the row MUST
project to identifiers-only — a declared projection intent never fails open
to the full row (ADR-005).

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

- **Performance:** trust filtering adds no OpenRegister queries; `scopeClaim`
  resolution adds at most one portalAccount lookup per collection read; `via`
  adds exactly one join query (row-capped) before the outer read in BOTH match
  directions (the reverse `match` is an in-memory per-row set membership, no
  extra query). Manifest aggregation stays a single pass over installed apps.
- **Accessibility:** no portaliq UI change in this slice; the SPA renders the
  (already filtered) manifest exactly as before.
- **Internationalization:** no new user-facing strings on the portaliq side
  (error payloads are machine-readable keys); any future UI strings MUST ship
  Dutch and English (ADR-007).
- **Security (ADR-005):** every new path fails closed — unknown trust → `low`,
  unresolvable claim → empty, invalid `via` → empty, unauthorised action →
  403, assertion-as-bearer → 401; subject identity is only ever derived
  server-side from the validated session.

## Acceptance Criteria

- [ ] A provider exposing `getAudiences()` is consulted for each listed audience; `getAudience()`-only providers behave exactly as v1
- [ ] Below-`minTrust` entries are absent from the manifest AND rejected 403 on direct read/create/action calls
- [ ] `scopeClaim` collections scope by the server-resolved claim; absent claim → 200 with zero rows; client-supplied `claims` never reaches a write
- [ ] `via` collections return only per-row-verified targets referenced by the subject's verified join rows; invalid/nested `via` → zero rows
- [ ] `via.match: 'scopeField'` (reverse) returns only outer rows whose own `scopeField` value (scalar or any array element, strict) is in the verified set; forward `match: 'id'`/absent is byte-for-byte unchanged; empty set / absent-null `scopeField` / malformed `match` → zero rows
- [ ] `POST /portal/api/actions/{appId}/{actionId}` authorises against the subject's own manifest, forwards with a ≈60s `X-Portal-Subject` assertion, relays the response
- [ ] An assertion presented as a session bearer is rejected 401
- [ ] Existing supplier-portal unit suite stays green (v1 manifests unchanged in behaviour)
- [ ] Rows of a `fields`-declaring collection contain only declared properties plus identifiers, on both direct and `via` read paths; absent `fields` → full rows; malformed `fields` → identifiers-only
- [ ] The assertion wire-format pin test asserts header alg and every claim explicitly

## Notes

- Canonical contract text: ADR-046 amendment 2026-07-06
  (`hydra/openspec/architecture/adr-046-portaliq-external-portal.md`, A2–A6).
  This spec is portaliq's enforcement view; per-app provider behaviour lands
  in each contributor's own `portal-contribution` change.
- OCP surfaces used: `OCP\App\IAppManager` + `Psr\Container\ContainerInterface`
  (provider discovery, unchanged), `OCP\Http\Client\IClientService` (A6
  forward), `OCP\Security\ISecureRandom` (assertion `jti`), `OCP\IConfig`
  (signing-secret sourcing, unchanged).
- Schema.org: no new entity is introduced — `claims` is a property on the
  existing `portalAccount` (which intentionally carries no `x-schema-org`
  marker: it is an auth-edge linking record, not a public entity; markers are
  schema-level only per the fleet convention).
- Receiving-app assertion verification (A6 consumer side) is out of scope
  here by design — tracked per app in the ADR-046 rollout waves.
- This spec was created by the `contract-v2` change (delta:
  `openspec/changes/contract-v2/specs/portal-contribution-contract/spec.md`);
  keep both in sync until the change archives.
- The "Read-side field projection" and "Frozen assertion wire format"
  requirements were added by the `field-projection` change (delta:
  `openspec/changes/field-projection/specs/portal-contribution-contract/spec.md`);
  same sync discipline until that change archives.
- The reverse `via.match` direction was added to "One-hop via join scoping" by
  the `reverse-scope-join` change (delta:
  `openspec/changes/reverse-scope-join/specs/portal-contribution-contract/spec.md`,
  tracking Conduction/portaliq#14); same sync discipline until it archives.

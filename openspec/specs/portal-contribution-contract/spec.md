# portal-contribution-contract Specification

**Status**: in-progress
**Scope**: portaliq
**OpenSpec changes**:

- [contract-v2](../../changes/contract-v2/)
- [field-projection](../../changes/field-projection/)
- [reverse-scope-join](../../changes/reverse-scope-join/)
- [portal-scoped-crud](../../changes/portal-scoped-crud/)
- [contribution-manifest-v3](../../changes/archive/2026-09-29-contribution-manifest-v3/)
- [portal-status-transitions](../../changes/archive/2026-09-29-portal-status-transitions/)
- [assignment-portal-file-upload](../../changes/assignment-portal-file-upload/)
- [portal-take-assessment](../../changes/portal-take-assessment/)
- [portal-scope-list-membership](../../changes/portal-scope-list-membership/)

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
re-stamp the scope field AFTER the merge so a patch can never move the row out
of the subject's scope (a verified list is re-stamped with the stored list
itself, a single value with the subject's reference), keep the stored
organisation exactly as it is (the tenant is stamped on create only: an update
MUST NOT overwrite it and MUST NOT add one where the stored object has none,
whatever the subject's portal or the payload says), and save with the id
preserved
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

#### Scenario: An update leaves the stored organisation alone

- GIVEN an object the subject owns that has no organisation, AND a subject whose portal has one
- WHEN the subject updates it (a withdrawal, an amendment, a row action)
- THEN the saved object still has no organisation; an object that had one keeps it, and an organisation in the payload changes nothing
- @e2e exclude backend write contract, covered by PHPUnit `tests/Unit/Service/PortalObjectWriterOrganisationTest.php`; no distinct UI surface

#### Scenario: A patch to a foreign-owned id is refused before any write

- GIVEN a subject AND an id that belongs to a DIFFERENT subject or tenant
- WHEN the subject PATCHes that id
- THEN ownership is re-verified against OpenRegister FIRST, the write is refused (the OpenRegister save is never called), and the response is 404 — closing Conduction/portaliq#16
- @e2e exclude write-IDOR security invariant — pinned by a PHPUnit test asserting the save is never called for a foreign id; no UI surface

### Requirement: Frozen assertion wire format

The A6 `X-Portal-Subject` assertion wire format MUST be treated as frozen
for receiver-side verifiers: header exactly `{"alg": "HS256", "typ": "JWT"}`
and the claim set `sub`, `audience`, `organisation`, `trust`, `jti`, `use`
(literal `"assertion"`), `iat`, `exp`, `iss` (literal `"portaliq"`), with
`exp - iat` equal to the 60-second assertion TTL. When the forwarded action
declares a `scopeClaim`, the assertion MUST carry exactly one additional
claim, named after that claim and holding the value resolved server-side
from the subject's portal account; it MUST NOT carry any other claim. A unit
test MUST pin every element of that shape so any drift fails loudly before it
can break domain-app verifiers templated against it.

#### Scenario: The assertion shape is pinned

- GIVEN a freshly minted `X-Portal-Subject` assertion for an action with no `scopeClaim`
- WHEN its header and claims are decoded
- THEN the header is exactly `{"alg": "HS256", "typ": "JWT"}` and the claim keys are exactly `sub`, `audience`, `organisation`, `trust`, `jti`, `use`, `iat`, `exp`, `iss` with `use = "assertion"`, `iss = "portaliq"`, and `exp - iat = 60`
- @e2e exclude wire-format pin, a PHPUnit compatibility test by definition; no UI or HTTP surface

#### Scenario: A declared scope claim adds exactly one claim

- GIVEN an action declaring `scopeClaim: "signerEmail"` and a subject whose account resolves it
- WHEN the assertion for that forward is decoded
- THEN the claim keys are the nine frozen keys plus `signerEmail`, and nothing else
- @e2e exclude wire-format pin; PHPUnit

### Requirement: Manifest UI configuration is presentation-only

The manifest MAY carry UI-configuration keys (collection `columns`/`detail`/
`defaultSort`/`defaultFilters`; action `fieldConfigs`/`optionsProviders`/
`submitLabel`/`successMessage`; contribution `pages`), consumed by the portal
frontend for rendering ONLY. They MUST NOT influence the action `fields`
whitelist, collection scoping, or read-side field projection. All keys are
optional; a `PortalManifestNormaliser` sanitises them fail-closed after trust
filtering, in the aggregate, and never throws.

#### Scenario: A field config never widens the create whitelist

- GIVEN an action whose `fields` whitelist is `["title"]` and a `fieldConfigs`
  entry marking `status` visible/required
- WHEN the manifest is normalised
- THEN the `status` config is removed and a submit still accepts only `title`
- @e2e exclude Normaliser fail-closed contract — the assertion is that presentation config CANNOT widen a server-side whitelist, which is decided in PortalManifestNormaliser before any render. Pinned by PortalManifestNormaliserTest::testFieldConfigForNonWhitelistedFieldIsDropped and, on the submit half, ContributionControllerTest::testActionWithDeclaredFieldsForwardsOnlyThoseFieldsIgnoringSmuggledOnes. A browser can only observe the field it was already allowed to see.

#### Scenario: A column naming a projected-away field never leaks it

- GIVEN a collection projecting `["title","status"]` and a column for `internalNotes`
- WHEN the collection is read and rendered
- THEN rows carry only title/status/identifiers and the column renders blank
- @e2e exclude The load-bearing half is a NEGATIVE on the wire — the projected-away value must never reach the client — and an empty table cell is the same pixel whether the field was withheld or merely absent, so a browser assertion cannot tell the leak from the non-leak. Pinned where it is decidable: PortalObjectReaderTest::testProjectionReturnsOnlyDeclaredFieldsPlusIdentifierAfterVerification and ::testProjectionKeepsReducedEnvelopeIdentifierAndDropsUnknownDeclaredFields.

### Requirement: Scoped option providers

An action field MAY declare an `optionsProvider`: `static` (`options[]`) or
`collection` (`{register,schema,labelField,valueField}`, populated by the portal
through the SUBJECT-SCOPED `/portal/api/collections/{register}/{schema}` endpoint,
so it can only offer values the subject may already read). Any other shape, or a
provider for a non-whitelisted field, is dropped fail-closed.

#### Scenario: A collection dropdown is scoped to the subject

- GIVEN a `collection` optionsProvider for `procest/supplierContract`
- WHEN the form is rendered for subject `s1`
- THEN the options are exactly the `supplierContract` rows `s1` may read
- @e2e exclude The claim is that a dropdown can offer NOTHING the subject may not already read — it is the scoped collection endpoint doing the work, and proving it in a browser needs a second subject's rows to exist and be shown absent, which is an assertion about data that is not there. Pinned by PortalManifestNormaliserTest::testOptionsProvidersValidateStaticAndCollectionAndDropMalformed for the provider shape, and by the scoping suite in PortalObjectReaderTest for what the endpoint may return.

### Requirement: Page composition with resolvable, same-contribution blocks

A contribution MAY declare `pages` of typed `blocks` (`collection`, `action`,
`detail`, `richText`, `cta`). Every `collection`/`action` reference MUST resolve
within the SAME contribution AFTER trust filtering; an unresolved/unknown block
is dropped, a zero-block page is dropped, and a contribution with no valid pages
gets one synthesised default page per `listable` collection (v2 rendering).

#### Scenario: A cross-contribution or trust-dropped reference is refused

- GIVEN a page block referencing another app's collection, or a trust-dropped action
- WHEN the manifest is normalised
- THEN the block is dropped (and its page too, if that empties it)
- @e2e exclude Normaliser fail-closed contract, asserted on the manifest structure before any page is rendered; the browser-visible consequence is a page that does not exist, which is not a thing a Playwright locator can be pointed at. Pinned by PortalManifestNormaliserTest::testPageBlocksResolveWithinContributionAndUnknownAreDropped and ::testAbsentPagesSynthesiseOneDefaultPerListableCollection.

### Requirement: v2 manifests are unchanged by normalisation

A manifest with no v3 keys MUST pass through with collections and actions
byte-identical, aside from an additive synthesised `pages` array.

#### Scenario: A pure v2 manifest round-trips

- GIVEN a v2 contribution (no v3 keys)
- WHEN normalised
- THEN every collection/action is unchanged and a default `pages` array is added
- @e2e exclude A byte-identity claim about a data structure ("unchanged aside from an additive pages array"). A browser sees a rendered page, which is precisely the thing that is allowed to differ, so it cannot witness the invariant. Pinned by PortalManifestNormaliserTest::testV2ManifestRoundTripsWithOnlyAdditivePages.

### Requirement: Server-enforced status transitions

A `type: update` action MAY declare `set` — a map of WHITELISTED field → fixed
value the SERVER applies over the client body (after whitelisting, before the
write). A collection MAY declare `rowActions`: entries resolving by id to a
`type: update` action or to an endpoint row action in the same contribution,
rendered as per-row buttons. An entry MAY be a string id or an object with an
`id`; only the id is read. A collection MAY also declare the singular
`rowAction` string, which SHALL be read as one more entry. An endpoint row
action is an action with a non-empty instance-local `endpoint` that is not a
`create`, `update` or `propose-change` action and that declares `rowField`, a
field name matching `^[a-zA-Z][a-zA-Z0-9_]*$`; an endpoint action without a
well-formed `rowField` SHALL NOT resolve as a row action. The `PATCH` update
endpoint accepts `?action=<id>` to select which update action to apply. The
transition target is tamper-proof (the client can never choose an arbitrary
value); malformed `set`/`rowActions` are dropped fail-closed; ownership
re-verification and scope re-stamp still run.

#### Scenario: A transition target cannot be tampered with

- GIVEN an update action `close` with `fields: [status]` and `set: {status: closed}`
- WHEN the subject PATCHes their own row with `?action=close` and body `{status: "hacked"}`
- THEN the saved `status` is `closed` (server `set` overrides the client) and 200 is returned
- @e2e exclude The attack is a HAND-CRAFTED body — the portal UI never offers a way to send `status: "hacked"`, so driving this through the browser would prove the UI is well behaved, not that the server is. It is an API-level tamper assertion. Pinned by PortalManifestNormaliserTest::testSetKeepsOnlyWhitelistedScalarTransitionValues and the PATCH-path cases in ContributionControllerTest.

#### Scenario: rowActions and set fail closed

- GIVEN `set: {status: closed, subjectRef: other}` and `rowActions: [close, createTicket, ghost]`
- WHEN normalised
- THEN `set` keeps only `{status: closed}` and `rowActions` keeps only `[close]`
- @e2e exclude Normaliser fail-closed contract asserted on the manifest structure — the browser-visible consequence is a button that is not rendered and a field that is not written, both absences. Pinned by PortalManifestNormaliserTest::testRowActionsResolveOnlyToUpdateActionsInContribution and ::testMalformedSetIsDropped.

#### Scenario: An endpoint action with a rowField resolves as a row action

- GIVEN an endpoint action `pay` with `rowField: invoiceId`, and a collection declaring `rowAction: pay` and `rowActions: [{id: close}]`
- WHEN normalised
- THEN the collection's `rowActions` is `[close, pay]` and carries no `rowAction` key
- @e2e exclude Normaliser contract on the manifest structure. Pinned by tests/Unit/Contribution/RowActionResolverTest.php::testSingularRowActionAndObjectEntriesResolve.

#### Scenario: An endpoint action resolves as a row action

- GIVEN an endpoint action `sign` with `rowField: signingRequestId` and `rowActions: [{id: sign}]`
- WHEN normalised
- THEN `rowActions` keeps `sign` with kind `endpoint`
- @e2e exclude Normaliser contract; pinned by PortalManifestNormaliserTest

#### Scenario: An endpoint action without a rowField is not a row action

- GIVEN an endpoint action `pay` with no `rowField`, or with `rowField: "invoice id"`, named by a collection's `rowAction`
- WHEN normalised
- THEN the collection carries no `rowActions` and no `rowAction`, and `pay` stays in the contribution's `actions`
- @e2e exclude The browser-visible consequence is a button that is not rendered, an absence. Pinned by tests/Unit/Contribution/RowActionResolverTest.php::testAnEndpointActionWithoutAWellFormedRowFieldIsNotOffered.

### Requirement: Scoped file attachment on a subject-owned object

`POST /portal/api/collections/{register}/{schema}/{id}/files` (multipart field
`file`) attaches a subject-uploaded file to an existing object. The collection
MUST opt in explicitly with `filesUpload: true` — an absent or false flag is a
403 and no attach is attempted. Ownership MUST be re-verified through the same
scoped read the collection declares (`scopeField` / `scopeClaim` / `via`)
BEFORE the file is accepted; a foreign or absent id is a single 404 with
nothing written, carrying no body that distinguishes the two. The file lands in
the object's OpenRegister folder through OR's shared `FileService` (ADR-022,
never through `IRootFolder` directly); portaliq is the trusted scoper, so OR
RBAC is bypassed only after portaliq's own scope check has passed. `PortalFileWriter`
owns the attach and is the only writer on this path.

#### Scenario: A subject attaches a file to a row they own

- GIVEN a collection declaring `filesUpload: true` and a row the subject owns
- WHEN the subject uploads a file through the portal's upload block
- THEN the file is attached to that object via OR's file service
- AND the upload block reports the attachment and the file appears on the row
- Covered end to end by `tests/e2e/portal-document-download.spec.ts` ("a subject downloads a file on a row they own", anchored with `@e2e`): it uploads through the detail card's upload block on an owned row, waits for the block's confirmation, and finds the file in the row's download list.

#### Scenario: A non-opted-in collection and a foreign id both refuse before any write

- GIVEN a collection WITHOUT `filesUpload`, and separately a foreign/absent id
  on a collection that has it
- WHEN an attach is attempted against either
- THEN the first is 403 and the second is 404
- AND `PortalFileWriter::attachFile()` is never reached in either case
- @e2e exclude Fail-closed ordering, not a UI surface — the assertion is that the writer is NEVER CALLED (`expects($this->never())->method('attachFile')`), which is observable only at the seam. Pinned by ContributionControllerTest::testUploadRequiresTheCollectionToOptIntoFileUploads and ::testUploadForeignOrAbsentObjectIs404BeforeAnyAttach; a browser can see the status code but not that no write was attempted.

### Requirement: Scoped schema introspection

`GET /portal/api/schemas/{schema}` returns an OpenRegister schema definition by
slug so the portal engine can build table headers and forms from the same
source the server validates against. The caller MUST hold a valid portal
session (401 otherwise) and MAY only introspect a schema their own aggregated
manifest references — through a collection or an action, in any register.
An unreferenced slug is 403 and an unresolvable one is 404, both decided before
any definition is read. OR RBAC is bypassed inside `PortalSchemaReader` because
a schema is metadata rather than subject data; the shape returned is
OpenRegister's own (`properties` etc.) so the SPA store consumes it unchanged.

#### Scenario: A schema the manifest references is served, one it does not is refused

- GIVEN a subject whose manifest references schema `exampleDocument` via a
  collection, and an unrelated schema `secretThing` it references nowhere
- WHEN the subject requests each definition
- THEN `exampleDocument` is returned in OpenRegister's own shape
- AND `secretThing` is refused without its definition being read
- @e2e exclude Server-side metadata contract with no distinct UI surface — the SPA consumes this response to build a form it renders elsewhere, so a browser assertion would be testing the form, not the gate. Pinned by ContributionControllerTest::testSchemaServesADefinitionReferencedByACollection, ::testSchemaServesADefinitionReferencedByAnAction and ::testSchemaRefusesASchemaTheManifestDoesNotReference.

#### Scenario: An anonymous caller is refused without a schema being read

- GIVEN a request carrying no valid portal session
- WHEN a schema definition is requested
- THEN the response is 401
- AND `PortalSchemaReader` is never consulted
- @e2e exclude Fail-closed ordering, not a UI surface — the assertion is that the reader is NEVER CONSULTED, observable only at the seam. Pinned by ContributionControllerTest::testSchemaRefusesAnAnonymousCallerWithoutReadingASchema.

### Requirement: An action MUST be able to declare which of its fields are references

A `type: create` or `type: update` action SHALL be able to declare
`crossRefs`: a map from a whitelisted field name to the `register`, `schema`
and `scopeField` the value in that field must resolve inside, with an
optional `required` flag and an optional `scopeClaim`. A declaration that is
malformed, that names a field the action does not whitelist, or that omits
any of the three required keys SHALL remove the ACTION from the manifest
rather than only the declaration.

#### Scenario: A sound declaration survives normalisation
@e2e exclude {a manifest shape, asserted in tests/Unit/Contribution/CrossRefConfigNormaliserTest.php::testASoundDeclarationIsKept}

- **GIVEN** a create action whitelisting `tegenZaakId` and declaring it as a
  reference to `dossiq/case` scoped by `portalSubject`
- **WHEN** the manifest is normalised
- **THEN** the action SHALL keep its declaration with `required` resolved

#### Scenario: A guard that could not be read takes its action with it
@e2e exclude {a manifest shape, asserted in tests/Unit/Contribution/CrossRefConfigNormaliserTest.php::testAMalformedDeclarationDropsTheAction}

- **GIVEN** a create action declaring a reference with no `schema`
- **WHEN** the manifest is normalised
- **THEN** the action SHALL be absent from the manifest

#### Scenario: A guarded action is never anonymous
@e2e exclude {a manifest shape, asserted in tests/Unit/Contribution/CrossRefConfigNormaliserTest.php::testAGuardedActionLosesItsAnonymousFlag}

- **GIVEN** a create action declaring both `crossRefs` and `anonymous: true`
- **WHEN** the manifest is normalised
- **THEN** the action SHALL keep its references and lose `anonymous`

### Requirement: A declared cross reference must resolve inside the subject's own scope

Before a create or an update reaches storage, Portaliq SHALL resolve every
declared reference in the write body through the subject-scoped read. A
reference that does not resolve SHALL refuse the whole write with HTTP 403,
`error: cross_ref_refused` and the field that failed. A declared reference
the client left out SHALL refuse only when it is `required`.

#### Scenario: A citizen names somebody else's case
@e2e exclude {needs two citizen sessions against a live portal; asserted in tests/Unit/Service/PortalCrossRefGuardTest.php::testAReferenceOutsideTheSubjectsScopeRefuses}

- **GIVEN** a create action declaring `tegenZaakId` as a reference to the
  citizen's own cases
- **WHEN** the body names a case that is not theirs
- **THEN** the write SHALL be refused and nothing SHALL be stored

#### Scenario: A citizen names their own case

- **GIVEN** that same action
- **WHEN** the body names a case the citizen may already read
- **THEN** the write SHALL proceed
- @e2e exclude {the happy path of the above; asserted in tests/Unit/Service/PortalCrossRefGuardTest.php::testAReferenceInsideTheSubjectsScopePasses}

#### Scenario: A required reference that was left out

- **GIVEN** an action declaring `tegenZaakId` as required
- **WHEN** the body omits it
- **THEN** the write SHALL be refused naming that field
- @e2e exclude {asserted in tests/Unit/Service/PortalCrossRefGuardTest.php::testARequiredReferenceThatIsAbsentRefuses}

### Requirement: An action MUST be able to declare a file field

A `type: create` or `type: update` action SHALL be able to mark a whitelisted
field as a file field with `fieldConfigs.<field>.type: file`, plus optional
`multiple` (boolean), `accept` (extensions such as `.pdf` or MIME types such as
`image/*`) and `maxSizeMb` (1 to 50). The normaliser SHALL keep `type` only when
its value is `file` and the action is a create or update action, SHALL coerce
`multiple` to a strict boolean, SHALL drop every malformed `accept` entry and
keep at most 20, and SHALL clamp `maxSizeMb` into 1 to 50. A file config on a
field outside the whitelist SHALL be dropped with the rest of that config, as
every field config already is.

#### Scenario: A sound file field survives normalisation
@e2e exclude {a manifest shape, asserted in tests/Unit/Contribution/ActionConfigNormaliserFileFieldTest.php::testASoundFileFieldIsKept}

- **GIVEN** a create action whitelisting `attachmentRefs` with `fieldConfigs.attachmentRefs = {type: file, multiple: true, accept: [".PDF", "image/*"], maxSizeMb: 20}`
- **WHEN** the manifest is normalised
- **THEN** the config SHALL keep `type: file`, `multiple: true`, `accept: [".pdf", "image/*"]` and `maxSizeMb: 20`

#### Scenario: A malformed file config fails closed
@e2e exclude {a manifest shape, asserted in tests/Unit/Contribution/ActionConfigNormaliserFileFieldTest.php::testMalformedFileKeysAreDroppedOrClamped}

- **GIVEN** a field config `{type: "file", accept: ["pdf", "<script>", 7], maxSizeMb: 900}` and another `{type: "upload"}`
- **WHEN** the manifest is normalised
- **THEN** the first SHALL keep `type: file` with no `accept` and `maxSizeMb: 50`
- **AND** the second SHALL carry no `type` at all

#### Scenario: A file field on an endpoint action is not a file field
@e2e exclude {a manifest shape, asserted in tests/Unit/Contribution/ActionConfigNormaliserFileFieldTest.php::testAFileTypeOnAnEndpointActionIsDropped}

- **GIVEN** a `type: endpoint` action with a field config `{type: file}`
- **WHEN** the manifest is normalised
- **THEN** that config SHALL carry no `type`

### Requirement: A file field MUST never be written from a request body

On every create path (authenticated and anonymous) and on update, Portaliq
SHALL remove every declared file field from the whitelisted request body before
the write. Only the scoped field upload SHALL write a file field, with a
reference Portaliq produced itself.

#### Scenario: A typed reference is removed before the write
@e2e exclude {the attack is a hand-crafted body the portal form never sends; asserted in tests/Unit/Controller/ContributionControllerFileFieldTest.php::testCreateDropsATypedFileFieldValue and ::testUpdateDropsATypedFileFieldValue}

- **GIVEN** a create action whose `attachmentRefs` is a file field
- **WHEN** the client posts `{assignmentId: "a1", attachmentRefs: ["/admin/files/secret.pdf"]}`
- **THEN** the object SHALL be written with `assignmentId` only

### Requirement: A subject MUST be able to upload into a declared file field of an object they own

`POST /portal/api/collections/{register}/{schema}/{id}/fields/{field}?action=<id>`
(multipart part `file`) SHALL attach one file to the object and write its
reference into the field. The request SHALL name the action; it SHALL be one of
the subject's own create or update actions for this register and schema, and
the field SHALL be a declared file field of it, else 403 before any read.
The action's `minTrust` SHALL be re-checked (403). Ownership SHALL be proven
the way the action writes it: for a create action the stored scope field SHALL
equal the subject reference that create stamps, for an update action the value
SHALL resolve through the action's `scopeClaim`. A foreign or absent object
SHALL be one 404 with nothing attached. For a create action the object SHALL
have been created within the last 30 minutes (403 `upload_window_closed`).
The file SHALL be checked against `accept` (415) and `maxSizeMb` (413, default
20) before it is attached. After the attach, the Nextcloud file id SHALL be
written as a string: appended when the field is `multiple` and the schema
property is an array, else as the only value. A field already holding 20
references SHALL refuse with 409.

#### Scenario: A pupil attaches work to the submission they just created
@e2e exclude {no contribution on the e2e instance declares a `type: file` field (the demo register ships none), so there is no live form to drive this endpoint through; asserted in tests/Unit/Controller/PortalFieldFileControllerTest.php::testUploadAttachesAndAppendsTheReference}

- **GIVEN** a create action `createSubmission` with file field `attachmentRefs` (`multiple: true`) and a submission the subject created a minute ago holding `["4702"]`
- **WHEN** the subject uploads `essay.pdf` naming `action=createSubmission`
- **THEN** the file SHALL be attached to that submission through `PortalFileWriter`
- **AND** `attachmentRefs` SHALL be `["4702", "<new file id>"]`

#### Scenario: Every refusal happens before any attach
@e2e exclude {fail-closed ordering observable only at the seam (the writer is never called); asserted in tests/Unit/Controller/PortalFieldFileControllerTest.php::testForeignObjectIs404BeforeAnyAttach, ::testUndeclaredFieldIs403BeforeAnyRead and ::testCreateWindowClosedRefusesBeforeAnyAttach}

- **GIVEN** a field that is not a declared file field, a foreign object id, and an object the create action made an hour ago
- **WHEN** an upload is attempted against each
- **THEN** the answers SHALL be 403, 404 and 403 `upload_window_closed`
- **AND** `PortalFileWriter::attachFile()` SHALL never be reached

#### Scenario: Type and size are checked before the attach
@e2e exclude {needs crafted file bodies; asserted in tests/Unit/Service/PortalFileFieldPolicyTest.php::testAcceptMatchesExtensionOrMime and ::testSizeAboveTheLimitIsRefused}

- **GIVEN** a file field with `accept: [".pdf"]` and `maxSizeMb: 1`
- **WHEN** the subject uploads `run.exe`, then a 2 MB `essay.pdf`
- **THEN** the answers SHALL be 415 and 413, with nothing attached

### Requirement: The generic portal form MUST render a file field as a file picker

`SchemaForm` SHALL render a declared file field as a labelled file input
(honouring `multiple` and `accept`), SHALL leave it out of the create body,
SHALL refuse a picked file above `maxSizeMb` before anything is saved, and after
a successful create SHALL upload the picked files one at a time to the field
upload endpoint. When a file does not attach, the form SHALL keep the created
record and SHALL name the files that did not attach.

#### Scenario: The form renders a picker, not a text box
@e2e exclude {rendered with react-dom/server in tests/schema-form-file-field.spec.mjs::renders a file input for a file field; no contribution on the e2e instance declares a `type: file` field, so a live portal has no such form to render}

- **GIVEN** an action whose `attachmentRefs` is a file field with `multiple: true` and `accept: [".pdf"]`
- **WHEN** the form renders
- **THEN** `attachmentRefs` SHALL be an `<input type="file" multiple accept=".pdf">` with its label

#### Scenario: Create first, then upload each file
@e2e exclude {the submit flow is driven against a fake api in tests/schema-form-file-field.spec.mjs::creates then uploads each file and names a failed one}

- **GIVEN** two picked files and a create that succeeds
- **WHEN** the second upload fails
- **THEN** the create body SHALL not contain the file field
- **AND** both uploads SHALL target the created object's id
- **AND** the form SHALL name the second file as not attached

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

### Requirement: A collection MUST be able to declare a timed task driven by five endpoint actions

A collection with `kind: timedTask` SHALL carry a `timedTask` block naming the
ids of five actions in the same contribution: `available`, `start`, `answer`,
`submit` and `result`. Each named action SHALL be an endpoint action (an
instance-local `endpoint`). When any of the five is missing, names an action
that does not exist in the contribution after trust filtering, or names an
action without an endpoint, the normaliser SHALL remove the `timedTask` block
and the `kind`, so the collection renders as an ordinary list.

#### Scenario: A sound timed task survives normalisation
@e2e exclude {a manifest shape; asserted in tests/Unit/Contribution/TimedTaskConfigNormaliserTest.php::testASoundTimedTaskIsKept}

- **GIVEN** a collection `studentTests` with `kind: timedTask` and a block naming five endpoint actions of the same contribution
- **WHEN** the manifest is normalised
- **THEN** the collection SHALL keep `kind: timedTask` and the five action ids

#### Scenario: A block naming a missing or non-endpoint action is dropped
@e2e exclude {a manifest shape; asserted in tests/Unit/Contribution/TimedTaskConfigNormaliserTest.php::testABrokenTimedTaskFallsBackToAList}

- **GIVEN** a `timedTask` block whose `submit` names a trust-dropped action, and another whose `answer` names a `type: create` action without an endpoint
- **WHEN** the manifest is normalised
- **THEN** neither collection SHALL carry `kind` or `timedTask`

### Requirement: An endpoint action MUST be able to receive the subject's scope from the server

An endpoint action SHALL be able to declare `subjectField` (a field name
matching `^[a-zA-Z][a-zA-Z0-9_]*$`). When it does, the forward SHALL resolve the
action's scope value the way reads do (its `scopeClaim` from the subject's own
portal account, else the subject reference) and SHALL set that value in the
forwarded JSON body under `subjectField`, over any value the client sent, with
the body built from the action's `fields` whitelist (none declared means an
empty whitelist). When the value does not resolve, the forward SHALL answer 403
and SHALL NOT call the endpoint. A malformed `subjectField` SHALL remove the
action from the manifest.

#### Scenario: The learner reference comes from the server, not the browser
@e2e exclude {the attack is a hand-crafted body; asserted in tests/Unit/Controller/ContributionControllerSubjectFieldTest.php::testTheResolvedScopeOverridesAClientValue}

- **GIVEN** an endpoint action with `subjectField: learnerRef` and `scopeClaim: learnerRef`
- **WHEN** a pupil forwards it with `learnerRef: "someone-else"` in the body
- **THEN** the domain app SHALL receive the learnerRef resolved from the pupil's own account

#### Scenario: An unresolvable scope never reaches the domain app
@e2e exclude {an absence of an outbound call; asserted in tests/Unit/Controller/ContributionControllerSubjectFieldTest.php::testAnUnresolvableScopeIs403WithoutForwarding}

- **GIVEN** that action and a subject whose account has no `learnerRef` claim
- **WHEN** the subject forwards it
- **THEN** the answer SHALL be 403 and no request SHALL be made

### Requirement: The portal MUST let a subject take a timed task

For a `timedTask` collection the portal SHALL list the tasks `available`
returns and the subject's attempts, SHALL start an attempt through `start`
(asking for an access code when a task says it needs one), SHALL show one
question at a time with its renderer (choice, inline choice, text entry,
extended text, order, match, and a text answer for any other type), SHALL save
each answer through `answer` when it changes and when the subject navigates,
SHALL show which answers are not yet saved, SHALL count down to the attempt's
`deadlineAt` corrected by `serverNow`, SHALL submit through `submit` when the
subject confirms or the countdown reaches zero, and SHALL show the `result`
response read-only, or that the result is not released yet.

#### Scenario: A timed attempt from start to submit
@e2e exclude {learniq does not ship the endpoints yet, so no live contribution declares a timed task; the flow is driven against a fake api in tests/timed-task.spec.mjs::runs an attempt from start to submit}

- **GIVEN** a task with a 20 minute deadline and three questions
- **WHEN** the pupil starts it, answers two questions and submits
- **THEN** each answer SHALL be sent once per change with the attempt id and item id
- **AND** submit SHALL flush any unsaved answer before it is sent

#### Scenario: The countdown follows the server's deadline
@e2e exclude {a clock computation; asserted in tests/timed-task.spec.mjs::counts down from the server deadline, not the client clock}

- **GIVEN** a server that says it is 09:00:00 with a deadline of 09:37:30, and a client clock five minutes ahead
- **WHEN** the countdown is computed
- **THEN** it SHALL read 37:30

#### Scenario: Every item type renders its own control
@e2e exclude {rendered with react-dom/server in tests/timed-task.spec.mjs::renders a control per item type}

- **GIVEN** one item of each supported type and one hotspot item
- **WHEN** the question screen renders each
- **THEN** choice SHALL be radio buttons, inline choice a select, text entry an input, extended text a textarea, order a list with move buttons, match a select per source, and hotspot a text answer

### Requirement: A row-scoped forward MUST prove the row before it forwards

The portal SHALL serve `POST /portal/api/collections/{register}/{schema}/{id}/actions/{actionId}`
(optional `?collection=` to pick one of several collections on one schema). It
SHALL answer 401 without a subject. It SHALL answer 403, with no read and no
forward, when the collection is not in the subject's own aggregate, when its
normalised `rowActions` does not name `actionId`, when `actionId` is not an
endpoint row action of the same contribution, when the collection's or the
action's `minTrust` is not met, or when the endpoint is not an instance-local
path with an allowed method. It SHALL then read the row with the collection's
own scope (`scopeField`, `scopeClaim`, `via`) and answer a single 404, with no
forward, when the row is not the subject's or does not exist. The forwarded
body SHALL be built from the action's `fields` whitelist only (none declared
means an empty body), SHALL carry the proven row's id under the action's
`rowField` over any client value, and SHALL carry the resolved scope under a
declared `subjectField` (403 with no forward when it does not resolve). The
portal SHALL record one audit entry with verb `forward`, SHALL relay the domain
app's status and JSON body, and SHALL answer 502 on a transport failure.

#### Scenario: The row id reaching the domain app is the row the subject owns

- GIVEN the parent collection `salesInvoices` scoped by `customerId` on the claim `customerMasterId`, and the endpoint row action `pay` with `rowField: invoiceId`
- WHEN a guardian posts to the row-scoped forward for their own invoice with body `{invoiceId: "someone-elses", amount: 1}`
- THEN shillinq receives exactly `{invoiceId: "<the proven row id>"}` and the portal relays shillinq's `{checkoutUrl}`
- @e2e exclude The attack is a hand-crafted body; the portal UI sends no body at all. Pinned by tests/Unit/Controller/PortalRowActionControllerTest.php::testTheProvenRowIdIsStampedAndTheClientBodyIsIgnored.

#### Scenario: A row the subject does not own is never forwarded

- GIVEN the same collection and action
- WHEN a guardian posts to the row-scoped forward for an invoice id their scope does not read
- THEN the answer is 404 and no request reaches shillinq
- @e2e exclude An absence of an outbound call for another person's row, which needs a second subject's data to exist; pinned by tests/Unit/Controller/PortalRowActionControllerTest.php::testARowOutsideTheScopeIs404WithoutForwarding.

#### Scenario: An action the collection does not offer on rows is refused

- GIVEN a collection whose `rowActions` does not name `pay`, or a subject below the action's `minTrust`
- WHEN the subject posts to the row-scoped forward for `pay`
- THEN the answer is 403 and no row is read
- @e2e exclude An API-level refusal the UI never triggers; pinned by tests/Unit/Controller/PortalRowActionControllerTest.php::testAnActionTheCollectionDoesNotOfferIs403 and ::testTrustBelowTheActionIs403.

### Requirement: An endpoint row action MUST be offered only on the rows its rowWhen names

An endpoint row action MAY declare `rowWhen`: `{field, in}` with `field` a
field name and `in` a non-empty list of scalar values. A malformed `rowWhen`
SHALL keep the action from resolving as a row action. The portal SHALL show
the row button only on a row whose `field` holds one of the listed values, and
the row-scoped forward SHALL answer 409 `not_offered`, with no forward, for a
row that does not match, read after the scope check.

#### Scenario: A paid contribution offers no pay button and cannot be paid again

- GIVEN `pay` with `rowWhen: {field: state, in: [issued, partially-paid, overdue]}`
- WHEN the guardian's list holds one `issued` and one `paid` contribution, and the guardian posts to the row-scoped forward for the paid one
- THEN only the issued row shows the button, and the forward answers 409 with no request to shillinq
- @e2e exclude The UI half is asserted by tests/row-action.spec.mjs (offersRowAction and the rendered table); the API half by tests/Unit/Controller/PortalRowActionControllerTest.php::testARowOutsideRowWhenIs409WithoutForwarding. No leaf app declares rowWhen until shillinq's follow-up lands.

### Requirement: A collection MUST be able to name a notice field

A collection MAY declare `noticeField`, a field name. A malformed value SHALL
be dropped. When the selected row carries non-empty text in that field, the
portal SHALL show it as a notice above the detail fields and in the confirm
step of a row action; a row without it SHALL show no notice.

#### Scenario: A voluntary contribution says so before the guardian pays

- GIVEN the parent `salesInvoices` collection with `noticeField: invoiceNote`, and a voluntary contribution whose `invoiceNote` reads "This contribution is voluntary. Your child takes part whether you pay or not."
- WHEN the guardian opens it, and when the guardian presses its pay button
- THEN the sentence is shown as a notice in the detail card and in the confirm step
- AND a contribution that is not voluntary shows no notice
- @e2e exclude Rendered markup asserted by tests/row-action.spec.mjs::the confirm step shows the notice on a voluntary contribution, and none otherwise (the detail card renders the same rowNotice value); the normaliser half by tests/Unit/Contribution/RowActionResolverTest.php::testNoticeFieldIsKeptOnlyWhenWellFormed.

### Requirement: The portal MUST let a guardian pay a school contribution from its row

For an endpoint row action, the portal SHALL ask the subject to confirm first,
showing the action's label and the row's notice. On confirm it SHALL post to
the row-scoped forward with no body. When the answer is 2xx and carries a
`redirectUrl` or `checkoutUrl` that is an absolute `https:` URL, the portal
SHALL send the browser there; any other URL SHALL NOT be followed and the
portal SHALL say the next page could not be opened. A 2xx without a URL SHALL
show that it worked and reload the collection. A 503 or 502 SHALL say the step
is not available now; a 403, 404 or 409 SHALL say it can no longer be done for
this item. Portaliq SHALL NOT read, compute or send an amount.

#### Scenario: A guardian goes to the checkout for a contribution

- GIVEN a guardian with audience `parent`, an issued school contribution, and shillinq answering `{checkoutUrl: "https://pay.example.nl/checkout/abc"}`
- WHEN the guardian presses the row's pay button and confirms
- THEN the browser goes to `https://pay.example.nl/checkout/abc`
- @e2e exclude Needs shillinq's rowField follow-up and a bound payment provider; the decision is a pure function asserted by tests/row-action.spec.mjs::redirectTarget follows only an https URL from a 2xx answer.

#### Scenario: A checkout URL that is not https is not followed

- GIVEN a 2xx answer carrying `checkoutUrl: "javascript:alert(1)"` or `"http://pay.example.nl"`
- WHEN the portal handles the answer
- THEN the browser stays on the portal and the guardian reads that the next page could not be opened
- @e2e exclude A hostile answer no real receiver sends; asserted by tests/row-action.spec.mjs::redirectTarget refuses anything but https.

#### Scenario: Online payment is switched off at the school

- GIVEN shillinq answers 503 `{status: "deferred"}` because no payment provider is bound
- WHEN the guardian confirms
- THEN the guardian reads that the step is not available now and stays on the list
- @e2e exclude A provider state the test instance cannot switch; asserted by tests/row-action.spec.mjs::outcome maps each status to one message.

### Requirement: A cell holding a list shows one value per line

When a collection cell's value is a list of plain values (strings or numbers), the portal table and the site table MUST show each value on its own line, never joined with a comma. Values that are not lists MUST render as before.

#### Scenario: Report card grades read one per line
- GIVEN a report card row whose `gradeLines` is `["Rekenen: 7,9", "Taal: 8,3"]`
- WHEN a guardian opens "My child's report cards"
- THEN "Rekenen: 7,9" and "Taal: 8,3" appear on separate lines
- @e2e exclude pinned by `tests/array-cells.spec.mjs` (portal render and site formatter); live-checked on the primary-school instance

### Requirement: An attached action MUST be able to name one collection and its own app (REQ-ATO-001)

An endpoint action whose `attachTo` carries a `collection` SHALL be listed in
`attachedActions` only on that collection of `attachTo.app` (and schema). A
`collection` that is not a plain name SHALL attach nothing. `attachTo.app` MAY
be the declaring app itself. A forward that names `actionApp` SHALL be
authorised as an attached action, whatever app it names, so an action attached
to its own app's collection forwards without being one of that collection's
`rowActions`.

#### Scenario: pipelinq's reply lands on the questions only
- **GIVEN** pipelinq offers `replyToQuestion` with `attachTo: { app: "pipelinq", schema: "ticket", collection: "myQuestions" }` and has collections `ownRequests` and `myQuestions` on `ticket`
- **WHEN** the aggregate is resolved
- **THEN** `myQuestions` lists `replyToQuestion` and `ownRequests` does not
- test: PHPUnit `tests/Unit/Contribution/AttachedActionResolverTest.php` ("attach to collection narrows to that collection")

#### Scenario: The reply is forwarded with the proven question
- **GIVEN** the resident's question is waiting for them
- **WHEN** they send "Dank u, nog een vraag." on it
- **THEN** pipelinq receives `{ ticket: <question id>, message: "Dank u, nog een vraag." }`, never a ticket id from the browser
- test: PHPUnit `tests/Unit/Controller/PortalRowActionControllerTest.php` ("an action attached to its own collection forwards")

#### Scenario: A forged listing on another collection
- **GIVEN** a request names `replyToQuestion` on `ownRequests`
- **WHEN** the forward looks the action up
- **THEN** nothing is found and the answer is 403
- test: PHPUnit `tests/Unit/Contribution/AttachedActionResolverTest.php` ("the forward lookup honours the collection")

### Requirement: An attached action MUST carry its rowWhen to the renderer (REQ-ATO-002)

The listing of an attached action SHALL carry its `rowWhen`. A renderer SHALL
leave the action off a record whose field does not hold one of the listed
values. The forward SHALL refuse such a record with 409 and forward nothing.

#### Scenario: The reply shows while the question waits for the resident
- **GIVEN** `replyToQuestion` has `rowWhen: { field: "status", in: ["awaiting_customer"] }`
- **WHEN** the resident opens a question with status `awaiting_customer`, and then one with status `converted`
- **THEN** the first shows "Reageren op het antwoord" and the second does not
- test: `tests/attached-actions.spec.mjs` ("an attached action shows only on the rows its rowWhen names")

#### Scenario: A reply on a converted question
- **GIVEN** a question with status `converted`
- **WHEN** a client forwards `replyToQuestion` on it anyway
- **THEN** the answer is 409 and nothing is forwarded
- test: PHPUnit `tests/Unit/Controller/PortalRowActionControllerTest.php` ("an attached action outside its rowWhen is 409")

### Requirement: A claim-scoped create stamps the claim

When a `create` action declares `scopeClaim`, the writer MUST stamp the action's `scopeField` with the claim value resolved server side from the subject's own `portalAccount`, over any client value, exactly as the read path resolves it. When the claim is absent the create MUST be refused with 403 and nothing written. Without `scopeClaim` the stamp MUST remain the subject's `subjectRef`.

#### Scenario: A guardian's absence report carries the guardian's learniq reference

- GIVEN a guardian whose portal account carries `claims.learniq.guardianRef`
- AND learniq's action `createExcuseRequest` with `scopeField: submittedByRef` and `scopeClaim: guardianRef`
- WHEN the guardian reports their child absent
- THEN the stored report's `submittedByRef` is the guardian's learniq reference
- @e2e learniq `tests/e2e/po-parent-flows.spec.ts`

#### Scenario: No claim, no write

- GIVEN a subject whose portal account lacks the declared claim
- WHEN they submit the create action
- THEN the answer is 403 and nothing is written
- @e2e exclude covered by PHPUnit `ContributionControllerTest::testAClaimScopedCreateWithoutTheClaimIsRefused`

### Requirement: A collection MAY group its rows by a declared field

A contribution collection MAY declare `groupByField`, the row field whose value groups the rows. The normaliser MUST keep it only when it is a non-empty string naming one of the collection's projected `fields`, or any field when the collection projects none, and MUST drop it otherwise. When a collection keeps `groupByField` and its rows carry two or more distinct values, the portal MUST show one table per value, each under its own heading. The heading MUST be the name of the row with that id in the contribution's `guardianAudience.children` collection when there is one, else the value itself; rows without a value MUST come last under a heading reading "Other". With fewer than two groups the portal MUST show one table, as without the key.

#### Scenario: A guardian with two children sees one table per child
- GIVEN learniq's `parentGrades` declares `groupByField: 'learnerRef'` and `guardianAudience.children: 'parentChildren'`
- AND a guardian has grades for two children
- WHEN the guardian opens their child's grades
- THEN they see one table per child, each headed by the child's name
- @e2e exclude grouping and naming pinned by `tests/collection-groups.spec.mjs`; the live check on the primary-school instance is in the PR

#### Scenario: One child shows one table
- GIVEN the same collection and a guardian with one child
- WHEN they open the grades
- THEN they see one table without a child heading
- @e2e exclude pinned by `tests/collection-groups.spec.mjs` ("one child, or no group field, renders ungrouped")

#### Scenario: A group field the rows do not carry is dropped
- GIVEN a collection projecting `fields: ['value']` that declares `groupByField: 'learnerRef'`
- WHEN the manifest is normalised
- THEN `groupByField` is dropped
- @e2e exclude pinned by `PortalManifestNormaliserTest::testAGroupByFieldIsKeptOnlyWhenItNamesAProjectedField`

### Requirement: A page MAY be the record page of a collection

A contribution page MAY declare `record` with a `collection` id and optional `titleFields`. The normaliser MUST keep `record` only when its collection resolves in the same contribution, and MUST keep `titleFields` only as a list of non-empty strings. The portal MUST open such a page on the rows of that collection. Choosing a row MUST open the record: a heading with the record's title fields, a way back to the list when there is more than one row, and the page's other blocks. With exactly one row the portal MUST open that record directly. A record link to a row outside the subject's own rows MUST open nothing of it and say so.

#### Scenario: A guardian opens one child
- GIVEN learniq's "Mijn kinderen" page declares `record: {collection: 'parentChildren'}`
- AND a guardian with two children opens it
- WHEN she picks Vera
- THEN she sees a heading "Vera Hulstkamp", a button back to her children, and Vera's blocks
- @e2e exclude rendered by `tests/record-page.spec.mjs`; the live walk through on the primary-school instance is learniq's `tests/e2e/po-parent-flows.spec.ts`

#### Scenario: A record page whose collection is unknown keeps its blocks but loses `record`
- GIVEN a page declaring `record: {collection: 'unknown'}`
- WHEN the manifest is normalised
- THEN the page has no `record` key
- @e2e exclude pinned by `RecordPageNormaliserTest::testARecordIsKeptOnlyWhenItsCollectionResolves`

### Requirement: A block on a record page MAY narrow its rows to the open record

A `collection`, `kpi` or `calendar` block (per calendar source) MAY declare `recordField` and `recordKey` (default `id`). On an open record the portal MUST show only the rows whose `recordField` value equals the record's `recordKey` value, or, when the row holds a list there, contains it. A block or source MAY also declare `recordGroupsField`: a row that names groups there MUST show only for the open record's groups (the rows of the contribution's `guardianAudience.groups` collection that link to the record), or, without an open record, for the groups of every row of that collection; a row that names no group shows for everyone. The narrowing MUST only ever subset the rows the server already scoped to the subject.

#### Scenario: A school trip for another group stays off Vera's page
- GIVEN school events for the whole school, for Vera's group and for another group, and a source with `recordGroupsField: 'cohortIds'`
- WHEN Vera's record is open
- THEN the school-wide event and her group's event show, the other group's does not
- @e2e exclude pinned by `tests/record-page.spec.mjs` ("group-bound rows show for the record's groups")

#### Scenario: Only Vera's report cards show on Vera's page
- GIVEN `parentReportCards` rows for two children and a block with `recordField: 'learnerRef'`
- WHEN Vera's record is open
- THEN only the rows whose `learnerRef` is Vera's id show
- @e2e exclude pinned by `tests/record-page.spec.mjs` ("rows narrow to the open record")

### Requirement: A kpi block MUST show figure cards from one row

A `kpi` block names a collection and `cards`, each with a `field`, a `label`, and optional `unit`, `details` (a list of `{field, label}`) and `highlight`. The normaliser MUST drop a card without a field or label and the block when no card survives. `pick: {field, direction}` chooses the row with the highest (`desc`) or lowest (`asc`) value of that field; without `pick` the first row counts. Without a row the portal MUST say there are no figures yet. An optional `caption: {field, label}` MUST show under the heading which value the cards read (for example the school year). A highlighted card MUST be marked in text, not by colour alone.

#### Scenario: A guardian reads her child's absence figures
- GIVEN an attendance summary row with 5 absent days, 3 with permission and 2 without, and 4 late arrivals of 35 minutes
- WHEN the kpi block renders
- THEN she reads "5 days" with "3 with permission, 2 without permission", "4 times" with "35 minutes", and the unexcused card is marked as needing attention
- @e2e exclude pinned by `tests/record-page.spec.mjs` ("kpi cards")

### Requirement: A calendar block MUST show dated rows as a list and a month

A `calendar` block names `sources`, each with a `collection`, a `startField`, a `titleField` or a fixed `title` (a row without a title value takes the fixed one), an optional `endField`, an optional `kind` label, an optional `only: {field, in}` that keeps only the rows whose field holds one of the listed values, and an optional `expand: {field, startField, endField, titleField}` that turns each element of a list field into its own item. The normaliser MUST drop a source whose collection does not resolve, and the block when no source survives. The portal MUST show the items from today onward as a list grouped by month, and a month view with previous and next buttons, both reachable by keyboard and readable on a phone.

#### Scenario: Holidays, school events and conference times share one calendar
- GIVEN school events, a report period holding holidays, and a booked conference time
- WHEN the guardian opens the calendar
- THEN she sees each as one item with its date and its kind, in date order
- @e2e exclude pinned by `tests/record-page.spec.mjs` ("calendar items")

### Requirement: A news block MUST show the subject's latest news

A `news` block MAY declare `limit` (1 to 20, default 3). The portal MUST show that many of the newest items of the subject's news feed. On an open record it MUST show only items whose target names the record's school (the contribution's `guardianAudience.schoolField`), one of its groups (`guardianAudience.groups`) or the record itself.

#### Scenario: Vera's page shows the news for her school and group
- GIVEN a feed with an item for Vera's school, one for her group and one for another group
- WHEN Vera's record is open
- THEN the news block shows the first two
- @e2e exclude pinned by `tests/record-page.spec.mjs` ("news narrows to the record")

### Requirement: A collection block MAY label its rows from a second collection

A `collection` block MAY declare `lookups`, each with `as`, a `collection` of the same contribution, a `matchField`, a `valueField`, and optional `recordField`, `values` (a map from value to label) and `fallback`. The normaliser MUST drop a lookup that misses a name or whose collection does not resolve. The portal MUST write under `as`, on each row, the `valueField` of the first row of the lookup collection whose `matchField` holds the row's id (narrowed to the open record through `recordField`), labelled through `values`, else `fallback`.

#### Scenario: Homework shows whether the child handed it in
- GIVEN three assignments of Vera's group and her submissions for two of them
- WHEN her homework table renders with a lookup `as: 'status'` over her submissions
- THEN the rows read "Ingeleverd", "Open" and "Te laat ingeleverd"
- @e2e exclude pinned by `tests/record-page.spec.mjs` ("a lookup labels each homework row") and `RecordPageNormaliserTest::testAGroupBoundBlockKeepsItsGroupFieldAndLookups`

### Requirement: The site MUST ask the portal API in the site's language

The site MUST send its own language as `Accept-Language` on every read of the portal API, so a contributing app that answers in the reader's language (learniq's parent sections) answers a Dutch site in Dutch, whatever language the visitor's browser prefers.

#### Scenario: A guardian on an English browser reads learniq's sections in Dutch
- GIVEN the Wilgenboom site is Dutch and the guardian's browser prefers English
- WHEN the site reads her contributions
- THEN the request carries `Accept-Language: nl` and learniq's sections read "Mijn kinderen"
- @e2e exclude pinned by `tests/portal-language.spec.mjs`; the live check reads the sections on the Wilgenboom site

### Requirement: The portal API MUST ask contributing apps in the portal's language

When portaliq asks an app for its contribution, the app MUST see the portal's language, without a change to `getContribution(array $subject)`. Of the portal's declared `locales`, the one the request asks for (`Accept-Language`, which the site sets to its own language) MUST be used; otherwise the portal's first locale. The portal is the one the site names (`X-Portaliq-Portal`), else the one for the host, else the subject's organisation's. Portaliq MUST set Nextcloud's `forceLanguage` request parameter to that language for the duration of the provider call only and MUST put it back afterwards, also when the provider throws. An instance-wide `force_language` and a `forceLanguage` the request carries itself MUST win. Without a portal or locales, Nextcloud MUST choose as before.

#### Scenario: A Dutch portal asked from an English browser
- GIVEN the Wilgenboom portal declares `locales: ['nl']`
- AND a request for its contributions carries `Accept-Language: en-US`
- WHEN learniq's provider translates its section labels
- THEN it translates them to Dutch, and after the call the request no longer forces a language
- @e2e exclude pinned by `ContributionLanguageTest::testTheRegistryAsksEachProviderInThePortalsLanguage`; the mechanism was checked against Nextcloud 34's own L10N factory (forced `nl` translates core "Settings" as "Instellingen", and the next lookup is English again)

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
  `openspec/specs/portal-contribution-contract/spec.md`);
  keep both in sync until the change archives.
- The "Read-side field projection" and "Frozen assertion wire format"
  requirements were added by the `field-projection` change (delta:
  `openspec/specs/portal-contribution-contract/spec.md`);
  same sync discipline until that change archives.
- The reverse `via.match` direction was added to "One-hop via join scoping" by
  the `reverse-scope-join` change (delta:
  `openspec/specs/portal-contribution-contract/spec.md`,
  tracking Conduction/portaliq#14); same sync discipline until it archives.
- The "Manifest UI configuration is presentation-only", "Scoped option
  providers", "Page composition with resolvable, same-contribution blocks", and
  "v2 manifests are unchanged by normalisation" requirements were added by the
  `contribution-manifest-v3` change (delta:
  `openspec/changes/archive/2026-09-29-contribution-manifest-v3/specs/portal-contribution-contract/spec.md`);
  enforced by `PortalManifestNormaliser` and frozen in hydra ADR-063; same sync
  discipline until it archives.
- The "Scoped single-object read" and "Scoped verified update" requirements
  were added by the `portal-scoped-crud` change (delta:
  `openspec/specs/portal-contribution-contract/spec.md`,
  ADR-062 Phase 1, closing Conduction/portaliq#16); same sync discipline until
  it archives.

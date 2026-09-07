# Design: contract-v2

## Architecture Overview

Contract v2 extends the four proven v1 seams in place — no new architecture:

```
SPA (bearer) ──► PortalAuthMiddleware (fail-closed 401)
                      │
                      ▼
        ContributionController
          index()      ── aggregated manifest (now trust-filtered)
          collection() ── PortalObjectReader (now scopeClaim + via aware)
          create()     ── PortalObjectWriter (now minTrust re-checked)
          action()     ── NEW: validate against own manifest → server-to-server
                            forward + X-Portal-Subject assertion → relay
                      │
                      ▼
        PortalContributionRegistry.aggregateFor(subject)
          • getAudiences() preferred, getAudience() fallback (A2)
          • drops entries whose minTrust exceeds subject trust (A3)
```

Key property: `collection()`, `create()`, and `action()` all authorise against
the output of `aggregateFor()`. Filtering trust **inside aggregation** therefore
enforces A3 on every data path with one source of truth; the controller
re-checks `minTrust` on the matched entry as defense in depth (ADR-005).

Every v2 manifest field is optional with a v1-equivalent default, so v1
providers and manifests are untouched: `minTrust` absent → `low`, `scopeClaim`
absent → scope by `subjectRef`, `via` absent → direct read, `method` absent on
an endpoint action → `POST`.

## Trust model (A3)

- Ordering: `low (1) < substantial (2) < high (3)`.
- Subject trust: taken from the validated session claim `trust`; **any value
  outside the vocabulary — including empty, legacy `dev`, `EH3` — normalises
  to `low`** (fail-closed). The dev-login mint switches to issuing `low`
  explicitly.
- Entry `minTrust`: absent → `low`; **an unrecognised value makes the entry
  unsatisfiable** (dropped for every subject) — a typo must never widen access.
- Enforcement points: (1) `aggregateFor()` filters collections and actions;
  (2) `collection()` / `create()` / `action()` re-check the matched entry;
  (3) a below-threshold direct request returns 403 before any OpenRegister or
  domain-app call.

## scopeClaim addressing (A4)

**Format** (decided here, documented for contributors):

- `scopeClaim: "appId.claimName"` — explicit: claim `claimName` in app
  `appId`'s namespace of the subject's `portalAccount.claims`.
- `scopeClaim: "claimName"` (no dot) — shorthand: resolved in the
  **contributing app's own** namespace (the `app` the contribution came from).
  So pipelinq declares `scopeClaim: "linkedContactId"` and portaliq resolves
  `claims.pipelinq.linkedContactId`.
- Grammar: `claimName` and `appId` match `[a-z][a-zA-Z0-9_]*`; the **first**
  `.` splits appId from claimName; anything malformed → claim treated as
  absent (fail-closed empty).

**Resolution** is server-side only, in the reader path: load the subject's own
`portalAccount` (portaliq register, `portalAccount` schema, filtered by
`subjectRef` + `audience`, `_rbac: false` / `_multitenancy: false`, per-row
verified exactly like every portal read), then take
`claims[appId][claimName]`. Non-string or empty value → absent. Absent →
**the collection contributes zero rows (200 + empty), never an error** — a
missing linkage is a normal state, not a fault. Claims are **never** read
from client input; the create whitelist keeps `claims` unreachable from any
client payload.

## via two-hop scoping (A5)

`via: {register, schema, scopeField, targetField}` — one hop, reader-only:

1. **Join pre-pass:** query `via.register`/`via.schema` with a query-side
   filter on `via.scopeField` = the collection's scope value (subjectRef or
   resolved claim), `_rbac: false` / `_multitenancy: false`, row-capped
   (500). `via.scopeField` supports **dot-path traversal** for nested
   properties (e.g. `betrokkeneIdentificatie.inpBsn`); the query-side filter
   is best-effort — the **per-row dot-path verification is the security
   boundary**, mirroring v1 `verifyScope()`.
2. **Collect targets:** union of `targetField` values from verified join rows
   (string or array of strings accepted; values are UUID references per the
   OR relations convention).
3. **Target read:** read the collection's register/schema and keep only rows
   whose `id` or `uuid` is in the collected set — membership verified per
   row, plus the same per-row organisation check as direct reads.

Fail-closed: structurally invalid `via` (missing/non-string members), a
nested `via`, or an empty verified join set → zero rows + a logged warning.
One hop maximum is contract law (ADR-046 A5): deeper chains must materialise
a direct subject-ref property in the domain schema.

## Endpoint bearer-forward actions (A6)

New route (registered before the `/portal/{path}` catch-all):

### `POST /portal/api/actions/{appId}/{actionId}`

**Request:** any JSON body — relayed verbatim to the domain endpoint (the
domain app validates it; portaliq never interprets it). Bearer required.

**Authorisation (fail-closed, same pattern as `create()`):** the subject's own
aggregated (already trust-filtered) manifest must contain, for contribution
`app === appId`, an action with `id === actionId`, a non-empty `endpoint`,
and an allowed `method`; the entry's `minTrust` is re-checked. Otherwise 403,
and no outbound request is made.

**Forward:**
- `endpoint` MUST be an instance-local absolute path (starts with `/`);
  full `http(s)://` URLs are rejected (SSRF guard). Resolved to an absolute
  URL via `IURLGenerator::getAbsoluteURL()`.
- `method` ∈ {GET, POST, PUT, PATCH, DELETE}, default POST.
- Headers: `X-Portal-Subject: <assertion JWT>` + `Content-Type:
  application/json`. The client's own `Authorization` header is **never**
  forwarded.
- Client: `OCP\Http\Client\IClientService`, ~10s timeout; transport failure →
  502 `{"error": "forward_failed"}` (mirrors the writer's 502 posture).

**Response:** the domain app's status code and JSON body are relayed as-is.

**Assertion:** minted by a new `PortalJwtService::createAssertion()` — HS256,
same secret sourcing as sessions (app-config `jwt_signing_secret`, instance
`secret` fallback), TTL 60 seconds, claims: `sub` (subjectRef), `audience`,
`organisation`, `trust` (normalised), `jti` (the **session's** jti, so the
receiving app can correlate/audit the originating session), `iat`/`exp`/`iss`,
plus **`use: "assertion"`**. `PortalSessionService::resolveFromBearer()`
rejects any token carrying `use: "assertion"` — an assertion can never be
replayed as a portal session (spec requirement "Subject assertions are not
portal sessions"). Receiving apps verify the assertion
themselves (out of scope here; per-app rollout waves).

## Database Changes

None (thin client, no own tables — ADR-001). The only persistence change is
the additive OpenRegister schema edit below.

### Register config edit (the whole config delta)

`lib/Settings/portaliq_register.json`:

- `portalAccount.properties.claims` — new optional object property,
  server-managed: `{appId: {claimName: uuid}}`. Example value:
  `{"pipelinq": {"linkedContactId": "00000000-0000-0000-0000-000000000000"}}`.
- `portalAccount.version` `0.1.0` → `0.2.0`; `info.version` `0.1.1` → `0.2.0`
  (immutable-cache/import bust).

Additive and optional — existing `portalAccount` objects validate unchanged;
imported through the existing `ConfigurationService::importFromApp()` repair
path. No data transformation, no migration class (see migration.md).

## Mixed-spec rationale

This change is `kind: code`. The single config edit (the `claims` property +
version bump, ~6 lines of JSON) rides inside it deliberately: it is
**thin glue** — the property is unusable and meaningless without the claim
resolver, reader scoping, and whitelist guard shipped by this same change,
and splitting six declarative lines into a separate config change would
create a cross-PR ordering dependency with zero independent value. This is
the **reverse direction** of the usual mixed-spec pattern (a tiny config
fragment inside a code spec, rather than tiny code inside a config spec);
the coupling test is the same: neither side ships usefully alone.

## Declarative-vs-imperative decision

ADR-031's declarative default ("business logic belongs in schema dialects,
not services") governs **domain apps' business logic**. Portaliq's contract
enforcement is not domain business logic — it is **hub infrastructure**: an
auth edge for non-Nextcloud subjects, cross-app manifest aggregation, and a
trusted-intermediary scoping boundary that deliberately runs with OR RBAC and
multitenancy off (`_rbac: false` / `_multitenancy: false`) precisely because
its subjects do not exist in the layers the declarative dialects act on.
Trust comparison, claim resolution, join verification, and assertion signing
are the security kernel of that boundary and stay imperative, unit-testable
service logic in portaliq's own code. What **is** declarative is the contract
itself: contributors express everything (`minTrust`, `scopeClaim`, `via`,
endpoint actions) as manifest data — no contributor writes portal code.

## Nextcloud Integration

- **Controllers:** `ContributionController` (index/collection/create updated,
  `action()` new; `#[PublicPage]` + `#[NoCSRFRequired]` + `PortalProtected`
  marker as today).
- **Services:** `PortalContributionRegistry`, `PortalObjectReader`,
  `PortalObjectWriter`, `PortalJwtService`, `PortalSessionService` (all
  extended in place). New OCP surfaces: `OCP\Http\Client\IClientService`
  (forward), `OCP\IURLGenerator` (endpoint resolution). Unchanged:
  `OCP\App\IAppManager`, `Psr\Container\ContainerInterface`,
  `OCP\Security\ISecureRandom`, `OCP\IConfig`.
- **Routes:** one new entry `contribution#action` in `appinfo/routes.php`,
  before the `/portal/{path}` catch-all.
- **Mappers/Entities / Events:** none.

## Security Considerations

- **Fail-closed everywhere (ADR-005):** unknown trust → `low`; unrecognised
  `minTrust` → entry unsatisfiable; malformed `scopeClaim` / absent claim →
  empty; invalid or nested `via` → empty; unknown action / missing endpoint /
  unmet trust → 403 with no outbound call; assertion-as-bearer → 401.
- **IDOR:** subject identity (subjectRef, audience, organisation, trust) only
  ever derives from the validated bearer; claims resolve only from the
  subject's **own** portalAccount; per-row verification stays on every read
  path including the join pre-pass and target membership.
- **SSRF:** forwarded endpoints restricted to instance-local absolute paths;
  no client-controlled URLs; the client's Authorization header is never
  forwarded.
- **Token confusion:** assertions carry `use: "assertion"` and are rejected
  by the session resolver; 60s TTL bounds replay against receiving apps.
- **Input:** action bodies are relayed, never evaluated; the create whitelist
  continues to drop `claims` and every non-declared field; no PII/claims
  values in logs.

## File Structure

```
lib/
  Contribution/PortalContributionRegistry.php   (A2 audiences, A3 filter)
  Contribution/IPortalContributionProvider.php  (docblock: v2 optional methods)
  Controller/ContributionController.php         (A3 re-checks, A6 action())
  Service/PortalObjectReader.php                (A4 claim scoping, A5 via)
  Service/PortalObjectWriter.php                (unchanged write; A3 via create path)
  Service/PortalJwtService.php                  (createAssertion, use-claim)
  Service/PortalSessionService.php              (assertion rejection, trust normalise)
  Portal/PortalContributionProvider.php         (demo: v2 vocabulary examples)
  Settings/portaliq_register.json               (claims property + version bump)
appinfo/routes.php                              (contribution#action)
tests/Unit/...                                  (new fail-closed matrices)
openspec/specs/portal-contribution-contract/spec.md (main spec, in-progress)
```

No `src/` change: the SPA already renders whatever the manifest returns.

## Seed Data

Seed objects use **nil UUIDs and obvious placeholders only** — every value
below is a placeholder, never a real identifier or secret. Needed so the
claim-scoping path (A4) is exercisable on a dev install; `via` and endpoint
actions are exercised by unit tests (their realistic seeds live in the
contributing apps' own changes).

### Schema: `portalAccount` (register `portaliq`)

| Field        | Object 1 (supplier w/ claims)                    | Object 2 (client, no claims) | Object 3 (supplier, other org)                     |
|--------------|--------------------------------------------------|------------------------------|----------------------------------------------------|
| audience     | supplier                                         | client                       | supplier                                            |
| identityType | dev                                              | dev                          | dev                                                 |
| identityRef  | EXAMPLE_KVK_00000000                             | EXAMPLE_PSEUDONYM_PLACEHOLDER | EXAMPLE_KVK_00000000                               |
| subjectRef   | dev-supplier                                     | dev-client                   | 00000000-0000-0000-0000-000000000000                |
| organisation | dev-org                                          | dev-org                      | EXAMPLE_ORG_PLACEHOLDER                             |
| displayName  | Voorbeeld Leverancier B.V. (placeholder)         | Voorbeeld Klant (placeholder) | Tweede Leverancier (placeholder)                   |
| status       | active                                           | active                       | active                                              |
| claims       | {"portaliq": {"exampleContactId": "00000000-0000-0000-0000-000000000000"}} | {} | {} |

**Related items per object:** none (auth-edge linking records; no files,
notes, tasks, or contacts attach to portal accounts).

`@self` envelope per object: register `portaliq`, schema `portalAccount`,
slug `dev-supplier-account` / `dev-client-account` / `second-supplier-account`.

Object 1's claim demonstrates the map shape end-to-end: the demo provider
declares one collection with `scopeClaim: "exampleContactId"` so a dev
install proves claim resolution (and Object 2 proves fail-closed empty).
Seeds are dev fixtures; production imports may prune them — they contain
placeholders only.

## Migration Plan

Deploy: merge → app upgrade re-imports the register (version bump) via the
existing repair path — no downtime, no data change. Rollback: revert the PR;
the `claims` property is additive, so pre-existing objects are untouched
either way (details in migration.md).

## Trade-offs

- **Trust filter in the registry vs per-endpoint checks:** chosen — filtering
  in `aggregateFor()` gives one source of truth that automatically covers
  every authorisation lookup; per-endpoint-only checks would repeat the
  ordering logic four times. Defense-in-depth re-checks kept because cheap.
- **`scopeClaim` dotted string vs structured `{app, claim}` object:** chosen
  the string — one line in a manifest, unambiguous grammar, and the bare
  shorthand covers the dominant own-app case; a structured object adds
  ceremony without expressiveness.
- **Forwarding session `jti` in the assertion vs a fresh `jti`:** chosen the
  session's jti — lets receiving apps correlate an action to the originating
  (revocable) session for audit; a fresh jti would be untraceable. The
  `use: "assertion"` claim carries the anti-replay distinction instead.
- **Instance-local endpoint paths only vs arbitrary URLs:** chosen local-only —
  closes SSRF entirely; cross-instance domain apps are not a fleet reality
  today (revisit only with an explicit allowlist design).
- **Fail-closed-empty vs 4xx for absent claims:** chosen empty — an unlinked
  account is a normal onboarding state; erroring would break the whole
  portal page for one unlinked contribution.

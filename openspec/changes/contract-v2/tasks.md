# Tasks: contract-v2

> Portaliq-side implementation of the ADR-046 amendment (contract v2, A2–A6).
> Checkbox budget: 9 tasks × 2 = 18 unindented `- [ ]` lines (cap 20).
> Acceptance criteria are plain bullets by design — do not convert them.

## Implementation Tasks

### Task 1: Trust vocabulary + normalisation at the session edge
- **spec_ref**: `openspec/changes/contract-v2/specs/portal-contribution-contract/spec.md#requirement-trust-ordering-and-manifest-filtering`
- **files**: `lib/Service/PortalSessionService.php`, `lib/Controller/SessionController.php`
- **acceptance_criteria**:
  - GIVEN a resolved subject WHEN its `trust` claim is missing or outside `low|substantial|high` (e.g. `dev`, `EH3`) THEN the subject is treated as `low`
  - GIVEN the dev-login mint WHEN a session is issued THEN it carries `trust: low` explicitly
  - Trust ordering helper exposes `low < substantial < high` and is the single comparison used everywhere
- [x] Implement
- [x] Test

### Task 2: Registry v2 — multi-audience discovery + minTrust manifest filtering
- **spec_ref**: `openspec/changes/contract-v2/specs/portal-contribution-contract/spec.md#requirement-multi-audience-provider-discovery`
- **files**: `lib/Contribution/PortalContributionRegistry.php`, `lib/Contribution/IPortalContributionProvider.php`
- **acceptance_criteria**:
  - GIVEN a provider with duck-typed `getAudiences(): array` WHEN aggregating THEN it is consulted iff the subject's audience is in the list; `getAudience()`-only providers behave exactly as v1
  - GIVEN entries declaring `minTrust` WHEN aggregating for a subject THEN below-threshold collections AND actions are absent from the manifest; unrecognised `minTrust` values drop the entry for every subject
  - Existing registry unit suite stays green (v1 compatibility)
- [x] Implement
- [x] Test

### Task 3: Fail-closed trust re-checks on read and create paths
- **spec_ref**: `openspec/changes/contract-v2/specs/portal-contribution-contract/spec.md#requirement-server-side-trust-enforcement-on-read-create-and-action`
- **files**: `lib/Controller/ContributionController.php`
- **acceptance_criteria**:
  - GIVEN a collection or create action with `minTrust` above the subject's trust WHEN called directly THEN 403 is returned before any OpenRegister call (defense in depth on top of the filtered aggregate)
- [x] Implement
- [x] Test

### Task 4: portalAccount `claims` property + register version bump (the whole config delta)
- **spec_ref**: `openspec/changes/contract-v2/specs/portal-contribution-contract/spec.md#requirement-server-managed-claim-map-and-scopeclaim-scoping`
- **files**: `lib/Settings/portaliq_register.json`
- **acceptance_criteria**:
  - `portalAccount.properties.claims` added as optional server-managed object (`{appId: {claimName: uuid}}`, nil-UUID example); `portalAccount.version` → `0.2.0`, `info.version` → `0.2.0`; `required` unchanged
  - See migration.md — no migration class; existing repair-path import only
- [x] Implement
- [x] Test

### Task 5: scopeClaim resolution + claim-scoped reads
- **spec_ref**: `openspec/changes/contract-v2/specs/portal-contribution-contract/spec.md#requirement-server-managed-claim-map-and-scopeclaim-scoping`
- **files**: `lib/Service/PortalObjectReader.php`, `lib/Controller/ContributionController.php`
- **acceptance_criteria**:
  - GIVEN `scopeClaim: "claimName"` or `"appId.claimName"` WHEN reading THEN the scope value is resolved server-side from the subject's own portalAccount (`claims[appId][claimName]`; bare form = contributing app's namespace) and used against `scopeField` with per-row verification
  - GIVEN an absent/malformed claim or scopeClaim WHEN reading THEN 200 with zero rows and no unscoped OR query
  - GIVEN a client payload containing `claims` WHEN creating THEN the field never reaches the OpenRegister write
- [x] Implement
- [x] Test

### Task 6: `via` one-hop join scoping in the reader
- **spec_ref**: `openspec/changes/contract-v2/specs/portal-contribution-contract/spec.md#requirement-one-hop-via-join-scoping`
- **files**: `lib/Service/PortalObjectReader.php`
- **acceptance_criteria**:
  - GIVEN `via: {register, schema, scopeField, targetField}` WHEN reading THEN join rows are per-row verified (dot-path supported), targetField refs collected, and only target rows whose id/uuid is in the verified set are returned (same `_rbac:false`/`_multitenancy:false` + org-check discipline; join pre-pass row-capped)
  - GIVEN a structurally invalid or nested `via` WHEN reading THEN zero rows and a logged warning (fail-closed)
- [x] Implement
- [x] Test

### Task 7: Subject assertion mint + token-confusion guard
- **spec_ref**: `openspec/changes/contract-v2/specs/portal-contribution-contract/spec.md#requirement-subject-assertions-are-not-portal-sessions`
- **files**: `lib/Service/PortalJwtService.php`, `lib/Service/PortalSessionService.php`
- **acceptance_criteria**:
  - GIVEN `createAssertion()` WHEN minting THEN HS256 with the existing secret sourcing, TTL 60s, claims sub/audience/organisation/trust/jti (session's jti) + `use: "assertion"`
  - GIVEN an assertion presented as an Authorization bearer WHEN resolving THEN 401 (resolveFromBearer rejects `use: "assertion"`)
- [x] Implement
- [x] Test

### Task 8: Endpoint action forward — route + controller + relay
- **spec_ref**: `openspec/changes/contract-v2/specs/portal-contribution-contract/spec.md#requirement-endpoint-bearer-forward-actions`
- **files**: `lib/Controller/ContributionController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN `POST /portal/api/actions/{appId}/{actionId}` WHEN the action (id + non-empty local-path endpoint + allowed method + satisfied minTrust) exists in the subject's own aggregated manifest THEN portaliq forwards server-to-server with `X-Portal-Subject` and relays status + JSON body; otherwise 403 with no outbound call
  - GIVEN a full `http(s)://` endpoint or transport failure THEN 403 (SSRF guard) resp. 502 `forward_failed`; the client's Authorization header is never forwarded
  - Route registered before the `/portal/{path}` catch-all; hydra gates (route-auth, route-reachability) green
- [x] Implement
- [x] Test

### Task 9: Demo provider v2 vocabulary + seed claims data
- **spec_ref**: `openspec/changes/contract-v2/specs/portal-contribution-contract/spec.md#requirement-multi-audience-provider-discovery`
- **files**: `lib/Portal/PortalContributionProvider.php`, `lib/Settings/portaliq_register.json`
- **acceptance_criteria**:
  - Demo provider exercises v2: `getAudiences()`, one `minTrust: substantial` entry, one `scopeClaim: "exampleContactId"` collection, one endpoint action with placeholder path
  - Seed portalAccount objects per design.md Seed Data (nil UUIDs / obvious placeholders only): dev-supplier with the example claim, dev-client without — proving claim scoping and fail-closed empty on a dev install
- [x] Implement
- [x] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`, `phpunit-unit.xml` suite, classes constructed directly)
- New/changed API endpoints covered by Newman/Postman tests (manifest filtering 403s, action forward 403/502 contract)
- No UI change in this slice — no new Playwright surface; existing portal e2e stays green
- All tests pass (`composer test`); `composer check:strict` green; fix pre-existing issues encountered in touched files in the same batch
- `@spec` tags on every changed method (gate-16); SPDX tags inside the main docblock
- No new user-facing strings (error payloads are machine keys) — i18n N/A this slice (ADR-007)
- Docs: contributor-facing contract v2 notes belong to the ADR-046 rollout docs, not portaliq `docs/` (hub-internal change)
- `openspec validate` passes

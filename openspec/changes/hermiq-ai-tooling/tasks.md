# Tasks: hermiq-ai-tooling

> Depends on `portaliq-mcp-adoption` having landed: the scaffold provider is gone and
> `portalMessage` carries its read-only dialect. Do not start Task 2 before that is true.

## Implementation Tasks

### Task 1: Declare the derived read dialects on the operational schemas
- **spec_ref**: `openspec/changes/hermiq-ai-tooling/specs/portaliq-mcp-surface/spec.md#requirement-derived-read-dialects-cover-the-operational-record`
- **files**: `lib/Settings/portaliq_register.json`
- **acceptance_criteria**:
  - GIVEN `portalSubmission`, `portalAuditEntry` and `portalNotification` WHEN each declares `x-openregister-mcp` THEN it MUST carry `search` + `get` only, `scope: 'read'`, `readOnlyHint: true`, and the filters listed in the spec — each a verified declared property, so `McpAnnotationValidator` accepts the import
  - GIVEN the full register WHEN the derived catalog is built THEN no derived tool id for `portaliq` ends in `.create`, `.update` or `.delete`
  - GIVEN `portalAccount` and `portalSession` WHEN the diff is reviewed THEN neither gains an `x-openregister-mcp` block
- [ ] Implement
- [ ] Test

### Task 2: Build the governed `PortaliqToolProvider` — curated writes + `trafficSummary`
- **spec_ref**: `openspec/changes/hermiq-ai-tooling/specs/portaliq-mcp-surface/spec.md#requirement-every-staff-write-action-is-a-curated-tool-governed-by-scope-and-reach`
- **files**: `lib/Mcp/PortaliqToolProvider.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN the provider WHEN its descriptors are enumerated THEN they are exactly `portaliq.trafficSummary`, `portaliq.updatePortalContent`, `portaliq.updatePageRegions`, `portaliq.applyTheme`, `portaliq.publishPortalPage`, `portaliq.sendPortalMessage`, `portaliq.revokeOrganisationSessions` — each a 2-segment id mapping to the production code path named in design.md
  - GIVEN every descriptor WHEN annotations are inspected THEN `scope` and `reach` are declared per the spec table, `trafficSummary` carries `readOnlyHint: true`, and `revokeOrganisationSessions` carries `destructiveHint: true`
  - GIVEN `revokeOrganisationSessions` WHEN it executes THEN it returns a count and never a session object, `jti` or claims blob; GIVEN any write tool WHEN invoked without the server-side authority its UI equivalent requires THEN it fails closed identically to the UI path
  - GIVEN the app boots WHEN the container resolves THEN the provider registers without DI error and `portaliq.ping` / `portaliq.describeApp` do not exist
- [ ] Implement
- [ ] Test

### Task 3: Declare approval requirements on the three high-impact tools
- **spec_ref**: `openspec/changes/hermiq-ai-tooling/specs/portaliq-mcp-surface/spec.md#requirement-high-impact-writes-require-a-human-approval-gate`
- **files**: `lib/Mcp/PortaliqToolProvider.php`, `tests/Unit/Mcp/`
- **acceptance_criteria**:
  - GIVEN `portaliq.publishPortalPage`, `portaliq.sendPortalMessage` and `portaliq.revokeOrganisationSessions` WHEN their descriptors are read THEN each declares that execution requires human approval, in the form hermiq's `human-approval-gate` consumes
  - GIVEN `updatePortalContent`, `updatePageRegions` and `applyTheme` WHEN their descriptors are read THEN none requires per-invocation approval (default-deny grant only)
  - GIVEN a test enumerating the catalog THEN the approval-required set is pinned exactly — adding a fourth or dropping one of the three fails the test
- [ ] Implement
- [ ] Test

### Task 4: Prove the portal edge cannot reach MCP
- **spec_ref**: `openspec/changes/hermiq-ai-tooling/specs/portaliq-mcp-surface/spec.md#requirement-mcp-tools-are-unreachable-from-the-external-portal-edge`
- **files**: `tests/Unit/Mcp/`, `appinfo/routes.php` (read-only reference)
- **acceptance_criteria**:
  - GIVEN a valid, unexpired portal bearer minted via `PortalSessionService` WHEN presented to the MCP listing and invocation paths THEN both reject it as unauthenticated, with a response indistinguishable from an invalid bearer's
  - GIVEN the control half WHEN the same bearer is presented to a `PortalProtected` `/portal/api/*` endpoint THEN it authenticates — proving the bearer is genuinely valid and the rejection above is the guard, not a broken fixture
  - GIVEN `appinfo/routes.php` and the `#[PublicPage]` controller set WHEN audited by test THEN no `/portal/*` route and no public method lists, proxies or invokes an MCP tool
- [ ] Implement
- [ ] Test

### Task 5: Audit every invocation through `AuditTrailService`
- **spec_ref**: `openspec/changes/hermiq-ai-tooling/specs/portaliq-mcp-surface/spec.md#requirement-every-tool-invocation-leaves-an-app-side-audit-record`
- **files**: `lib/Mcp/PortaliqToolProvider.php`, `tests/Unit/Mcp/`
- **acceptance_criteria**:
  - GIVEN any executed curated tool WHEN it completes THEN a `portalAuditEntry` records verb, target register/schema/id, timestamp and acting identity — and a test asserts the entry contains no payload field
  - GIVEN `AuditTrailService::record()` failing (OR unavailable) WHEN a granted tool executes THEN the action still completes and the gap is logged — reusing the service's existing never-throw contract, not reimplementing it
  - GIVEN a denied invocation (no grant / approval rejected) WHEN portaliq's audit trail is inspected THEN no portaliq-side entry was written for it
- [ ] Implement
- [ ] Test

### Task 6: Documentation + capability spec maintenance
- **spec_ref**: `openspec/changes/hermiq-ai-tooling/specs/portaliq-mcp-surface/spec.md#requirement-portaliq-exposes-no-hand-written-mcp-tool`
- **files**: `README.md`, `CHANGELOG.md`, `openspec/specs/`
- **acceptance_criteria**:
  - GIVEN the README WHEN this change lands THEN it documents the tool table (reads vs writes, scope×reach, the three approval-gated tools) and the ADR-046 rule that no tool is reachable from the portal edge
  - GIVEN the capability spec WHEN this change is archived THEN `openspec/specs/portaliq-mcp-surface/spec.md` reflects the base requirements from `portaliq-mcp-adoption` plus this delta's MODIFIED and ADDED requirements, listing both changes
  - GIVEN the CHANGELOG WHEN this change lands THEN it records the new derived read dialects and the governed provider
- [ ] Implement
- [ ] Test

## Verification

- `openspec validate hermiq-ai-tooling --type change --strict` passes.
- The `portaliq` MCP catalog contains exactly: eight derived read tools
  (`portalmessage`/`portalsubmission`/`portalauditentry`/`portalnotification` × `search`/`get`)
  and seven curated tools — nothing else, and no derived write verb.
- The three approval-gated tool ids match the spec exactly.
- The portal-bearer rejection test passes, and its control (the same bearer accepted at
  `/portal/api/*`) also passes — both halves, same run.

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- PHP verified the CI way in a container, against a baseline measured first — zero new failures
- Scoped PHPCS clean on every touched `lib/` file; `python3 -m json.tool` after every JSON edit
- `@spec` tags point at `openspec/specs/...`, never an archived change path (gate-46)
- No new user-facing strings — i18n N/A (tool descriptions are agent-facing English)
- `openspec validate` passes

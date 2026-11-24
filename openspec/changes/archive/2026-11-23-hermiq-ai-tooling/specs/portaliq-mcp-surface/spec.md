# portaliq-mcp-surface Delta: hermiq-ai-tooling

**Status**: planned
**Scope**: portaliq
**OpenSpec changes**:
- `portaliq-mcp-adoption` — deletes the app-template scaffold and declares the read-only inbox dialect (the base this delta extends)
- `hermiq-ai-tooling` — extends the surface to every internal staff action under hermiq's grant model (kind: code)

## Purpose

Extends the `portaliq-mcp-surface` capability established by `portaliq-mcp-adoption` from a
two-tool inbox read to portaliq's full internal action surface: derived read dialects on the
operational record, curated write tools for every staff action, hermiq `scope`×`reach` governance
with default-deny writes and human approval gates, an app-side audit record per invocation, and a
hard ADR-046 wall — MCP serves internal staff and their agents only, never the external portal
edge. The base change's requirements
`#requirement-a-curated-read-only-dialect-on-the-portal-inbox` and
`#requirement-portal-identity-and-session-objects-are-not-tools` remain in force unmodified and
are relied upon below.

## MODIFIED Requirements

### Requirement: Portaliq exposes no hand-written MCP tool

The system MUST NOT register any `IMcpToolProvider` implementation for app id `portaliq` **other
than the single governed provider `lib/Mcp/PortaliqToolProvider.php`**, and MUST NOT annotate any
Portaliq service method with `#[McpTool]`. The app-template scaffold prohibition is unchanged:
`ExampleToolProvider`, `portaliq.ping` and `portaliq.describeApp` MUST NOT return in any form.
Every tool the governed provider registers MUST use a 2-segment id `portaliq.{toolName}`, MUST map
to a named production code path (controller or service) that exists independently of MCP, and MUST
declare hermiq-consumable annotations (`scope`, `reach`, `readOnlyHint`/`destructiveHint` as
applicable). A tool in the provider with no production code path behind it MUST be treated as
scaffold and removed.

*(Modified from `portaliq-mcp-adoption`: the original blanket ban predates the fleet decision that
every app exposes its actions for agent automation; the ban on scaffold and on undeclared tools is
retained, the ban on the governed provider itself is lifted.)*

#### Scenario: Only the governed provider enumerates

- GIVEN OpenRegister builds the tool catalog for app id `portaliq`
- WHEN hand-written tools are listed
- THEN every one originates from `PortaliqToolProvider`
- AND neither `portaliq.ping` nor `portaliq.describeApp` appears

#### Scenario: An unannotated curated tool is a defect, not a default

- GIVEN a curated tool descriptor in `PortaliqToolProvider`
- WHEN its annotations are inspected
- THEN it MUST declare `scope` and `reach` explicitly
- AND a read tool MUST carry `readOnlyHint: true` so hermiq's fail-closed classifier does not grant-lock it as write/destructive

## ADDED Requirements

### Requirement: Derived read dialects cover the operational record

The system MUST declare `x-openregister-mcp` with the `search` and `get` verbs only,
`scope: 'read'` and `readOnlyHint: true`, on `portalSubmission`, `portalAuditEntry` and
`portalNotification`, with `search.filters` naming only real declared properties:
`portalSubmission` → `deliveryStatus`, `organisation`, `appId`, `actionId`; `portalAuditEntry` →
`verb`, `appId`, `organisation`, `subjectRef`; `portalNotification` → `status`, `channel`,
`appId`, `organisation`. The **derived** catalog MUST remain read-only: no derived tool id for
`portaliq` may end in `.create`, `.update` or `.delete` (all writes are curated tools under the
requirement below). `portalMessage`'s dialect from `portaliq-mcp-adoption` is unchanged, including
its refusal of `update`/`delete`.

#### Scenario: Which submissions failed delivery today

- GIVEN an agent asked "which submissions failed delivery today?"
- WHEN it invokes `portaliq.portalsubmission.search` filtered on `deliveryStatus` and bounded to today
- THEN the matching `portalSubmission` records are returned under OpenRegister RBAC
- AND no write tool is needed or invoked to answer

#### Scenario: The derived catalog stays read-only

- GIVEN every `x-openregister-mcp` block in `lib/Settings/portaliq_register.json`
- WHEN the derived catalog is built
- THEN it MUST contain no derived tool id ending in `.create`, `.update` or `.delete`

### Requirement: Every staff write action is a curated tool governed by scope and reach

`PortaliqToolProvider` MUST expose one tool per staff write action, each driving its existing
production code path and each annotated with hermiq's model (`scope` ∈
read/create/update/delete × `reach` ∈ self/user/instance/external): `portaliq.updatePortalContent`
(update × instance; `PortalObjectWriter` on `page`/`menu`/`glossaryTerm`),
`portaliq.updatePageRegions` (update × instance; the `PageRegionsController::update` path),
`portaliq.applyTheme` (update × instance; `PortalThemeResolver`/`PortalTokenCss` on a `portal`
object), `portaliq.publishPortalPage` (update × instance), `portaliq.sendPortalMessage` (create ×
external), and `portaliq.revokeOrganisationSessions` (delete × instance;
`SessionAdminController::revokeOrganisation`'s service path — accepting an `organisation` string
and returning a count, NEVER a session object, so
`#requirement-portal-identity-and-session-objects-are-not-tools` is preserved). The provider MAY
additionally expose `portaliq.trafficSummary` (read × instance; `PortalTrafficService`) as its
only curated read. Every write tool MUST be default-denied per agent until explicitly granted; a
tool invocation MUST NOT bypass any server-side authorization the equivalent UI action enforces
(annotations are restrict-only signals, never authorization).

#### Scenario: A write without a grant is refused

- GIVEN an agent with no grant for `portaliq.updatePortalContent`
- WHEN it invokes the tool
- THEN the invocation is denied without reaching `PortalObjectWriter`
- AND the denial is the governor's decision, not a portaliq 500

#### Scenario: The tool layer adds no authorization bypass

- GIVEN `portaliq.revokeOrganisationSessions` invoked by an agent whose operator lacks admin authority for the action
- WHEN the underlying service path re-checks authority server-side
- THEN the action fails closed exactly as the admin UI would
- AND no session object, `jti` or claims blob appears in the tool result either way

### Requirement: High-impact writes require a human approval gate

The descriptors for `portaliq.publishPortalPage`, `portaliq.sendPortalMessage` and
`portaliq.revokeOrganisationSessions` MUST declare that execution requires human approval, such
that hermiq's `human-approval-gate` interposes a person between the agent's proposal and the
effect. These three are the tools whose effect crosses to external people: publishing changes what
every portal visitor sees; a `portalMessage` lands in a citizen's inbox with possible legal effect
(`rechtsgevolg`); an organisation-wide revocation logs out every user of that organisation.
Draft-state writes (`updatePortalContent`, `updatePageRegions`, `applyTheme`) MUST NOT require
per-invocation approval once granted — the gate sits at the moment of irreversibility, not on
every keystroke.

#### Scenario: Publishing waits for a person

- GIVEN an agent granted the content tools that has drafted a page via `portaliq.updatePortalContent`
- WHEN it invokes `portaliq.publishPortalPage`
- THEN execution is suspended until a human approves the specific publication
- AND on approval the page becomes live at the portal edge; on rejection nothing visitor-facing changes

#### Scenario: Chat commands an incident response, with a human in the loop

- GIVEN an operator tells an agent "Jansen BV reports a stolen laptop — revoke all their portal sessions"
- WHEN the agent invokes `portaliq.revokeOrganisationSessions` for that organisation
- THEN the approval gate presents the action and its blast radius to a human before anything is revoked
- AND after approval every active `portalSession` for the organisation is revoked via the existing admin path

### Requirement: MCP tools are unreachable from the external portal edge

MCP tool listing and invocation for `portaliq` MUST be reachable only through internal Nextcloud
surfaces authenticated by a Nextcloud session or equivalent internal credential. No route under
`/portal/`, no `#[PublicPage]` controller, and no path authenticated by `PortalAuthMiddleware` /
`PortalProtected` may list, proxy or invoke an MCP tool. A portal bearer session
(`PortalSessionService`) MUST NOT be accepted as authentication for any MCP operation — a request
presenting only a portal bearer MUST be rejected as unauthenticated, regardless of the bearer's
validity or trust level. Nothing served to a portal visitor (`ContentController`,
`ContributionController`, `PortalPageController` payloads) may reference the MCP surface. (ADR-046:
portal visitors are not Nextcloud users; agents act for internal staff only.)

#### Scenario: A valid portal bearer cannot invoke a tool

- GIVEN a valid, unexpired portal bearer minted by `PortalSessionService`
- WHEN it is presented to any MCP listing or invocation path for `portaliq`
- THEN the request is rejected as unauthenticated
- AND the rejection is identical for a valid and an invalid bearer — no oracle about bearer validity

#### Scenario: The edge carries no MCP surface

- GIVEN the full route table and the `#[PublicPage]` controller set
- WHEN audited
- THEN no `/portal/*` route and no public controller method lists, proxies or invokes an MCP tool

### Requirement: Every tool invocation leaves an app-side audit record

Every executed tool invocation — derived read or curated write — MUST write a `portalAuditEntry`
through `AuditTrailService::record()`, carrying the fact of the invocation (verb, target
register/schema/id, timestamp, acting identity) and NEVER payload content, consistent with that
service's fact-only design. An audit write failure MUST NOT fail the audited invocation
(`record()` never throws; the gap is logged for reconciliation). Approval-gate denials and
grant denials are the governor's records and MUST NOT be double-written by portaliq.

#### Scenario: A granted write is on the record

- GIVEN an approved invocation of `portaliq.publishPortalPage`
- WHEN it executes
- THEN a `portalAuditEntry` exists recording the verb and target
- AND the entry contains no page content, only facts

#### Scenario: An audit outage does not silently block the action

- GIVEN `AuditTrailService` cannot write (OpenRegister unavailable)
- WHEN a granted tool executes
- THEN the action completes and the audit gap is logged for reconciliation

## Non-Functional Requirements

- **Performance:** Derived reads execute through the same `ObjectService` path as the UI; the
  catalog MUST enumerate only tools with a production code path (ADR-063 rule 3 — no
  tool-selection attention spent on dead entries).
- **Internationalization:** No new user-facing strings; tool `description` prose is agent-facing
  English. N/A for `nl_NL`/`en_US`.

## Acceptance Criteria

- [ ] Derived catalog: read-only dialects on `portalMessage` (base), `portalSubmission`, `portalAuditEntry`, `portalNotification`; no derived write verb anywhere
- [ ] `PortaliqToolProvider` exposes exactly the enumerated curated tools, each annotated `scope`×`reach` with correct hints
- [ ] `publishPortalPage`, `sendPortalMessage`, `revokeOrganisationSessions` declare required human approval
- [ ] A valid portal bearer presented to any MCP path is rejected as unauthenticated (tested, with a control proving the test can fail)
- [ ] Every executed invocation writes a fact-only `portalAuditEntry`
- [ ] `portalAccount` and `portalSession` still carry no `x-openregister-mcp` block, and no tool returns a session object, `jti` or claims blob

## Notes

- Base: `openspec/changes/portaliq-mcp-adoption/specs/portaliq-mcp-surface/spec.md` — this delta
  modifies `#requirement-portaliq-exposes-no-hand-written-mcp-tool` and leaves
  `#requirement-a-curated-read-only-dialect-on-the-portal-inbox` and
  `#requirement-portal-identity-and-session-objects-are-not-tools` untouched and load-bearing.
- hermiq references: `agent-tool-governance` (fail-closed classification: hint-less 2-segment ids
  are write/destructive; undeclared reach resolves to `external`), `agent-capability-reach`,
  `human-approval-gate`, `run-audit-log` — all in `hermiq/openspec/specs/`.
- Grounding verified in portaliq source: `SessionAdminController::revokeOrganisation` (instance
  admin + CSRF, deliberately not `#[AuthorizedAdminSetting]`), `PageRegionsController::update`,
  `TrafficController::summary`, `SubmissionReceiptService`, `NotificationDispatchService`,
  `AuditTrailService` (fact-only, never throws), and the schema property lists in
  `lib/Settings/portaliq_register.json`.

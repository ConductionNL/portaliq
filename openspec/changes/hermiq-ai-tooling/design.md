# Design: hermiq-ai-tooling

## Architecture Overview

Builds on the end state of `portaliq-mcp-adoption` (scaffold deleted; `portaliq.portalmessage.{search,get}`
derived). After this change portaliq uses two of ADR-063's chains, split by mutability:

1. **Schema-declared CRUD (reads only)** — `x-openregister-mcp` on `portalMessage` (from
   `portaliq-mcp-adoption`), plus `portalSubmission`, `portalAuditEntry`, `portalNotification`
   (this change). All `search` + `get`, `scope: 'read'`, `readOnlyHint: true`.
2. **`#[McpTool]` on services** — still unused; every candidate fits chain 1 or 3.
3. **Hand-written `IMcpToolProvider`** — **reintroduced under governance** as
   `lib/Mcp/PortaliqToolProvider.php`: all writes and the one genuine non-CRUD read. This is not
   the scaffold's return — every tool maps to a named production code path, carries hermiq
   `scope`×`reach` annotations, and the delta spec MODIFIES
   `#requirement-portaliq-exposes-no-hand-written-mcp-tool` accordingly.

Governance is hermiq's, consumed as data: `agent-tool-governance` default-denies write/destructive
tools and treats undeclared reach as `external` (fail closed); `human-approval-gate` interposes a
human where the descriptor demands it; `run-audit-log` records the agent side while portaliq's
`AuditTrailService` records the app side.

## The tool table — every staff action, one tool each, reads separated from writes

Reads (derived, chain 1 — grants are cheap, no approval):

| Tool id | Backing schema (verified properties used as filters) | scope × reach |
|---|---|---|
| `portaliq.portalmessage.search` / `.get` | `portalMessage` — from `portaliq-mcp-adoption`, unchanged | read × self |
| `portaliq.portalsubmission.search` / `.get` | `portalSubmission` — filters `deliveryStatus`, `organisation`, `appId`, `actionId` | read × instance |
| `portaliq.portalauditentry.search` / `.get` | `portalAuditEntry` — filters `verb`, `appId`, `organisation`, `subjectRef` | read × instance |
| `portaliq.portalnotification.search` / `.get` | `portalNotification` — filters `status`, `channel`, `appId`, `organisation` | read × instance |

Curated (chain 3, `portaliq.{toolName}` 2-segment ids):

| Tool id | Production code path it drives | scope × reach | Default | Approval |
|---|---|---|---|---|
| `portaliq.trafficSummary` | `PortalTrafficService` / `TrafficController::summary` | read × instance | grantable | — |
| `portaliq.updatePortalContent` | `PortalObjectWriter` on `page` / `menu` / `glossaryTerm` (draft-state edits) | update × instance | **deny** | — |
| `portaliq.updatePageRegions` | `PageRegionsController::update` path (`PortalRegionResolver`) | update × instance | **deny** | — |
| `portaliq.applyTheme` | theme application on a `portal` object (`PortalThemeResolver` / `PortalTokenCss`) | update × instance | **deny** | — |
| `portaliq.publishPortalPage` | `portal`/`page` status transition that makes content live at the edge | update × instance | **deny** | **required** |
| `portaliq.sendPortalMessage` | staff-originated `portalMessage` create (the write half of the inbox) | create × external | **deny** | **required** |
| `portaliq.revokeOrganisationSessions` | `SessionAdminController::revokeOrganisation` service path | delete × instance | **deny** | **required** |

Deliberately **absent**, with the reasoning on record:

- `portalMessage` `update`/`delete` — the mark-read hazard from `portaliq-mcp-adoption`'s design
  ("an agent flipping `read: true` hides an unread message") is unrefuted; still no tool.
- `portalAccount` / `portalSession` reads — forbidden by
  `#requirement-portal-identity-and-session-objects-are-not-tools`; `revokeOrganisationSessions`
  takes an `organisation` string and **returns a count, never a session object**, so it acts on
  sessions without ever exposing one (no `jti`, no `claims`).
- `devLogin`, `oidcStart`/`oidcCallback`, `nextcloud` handoff (`SessionController`) — auth-edge
  ceremonies bound to a browser flow; an agent minting portal sessions is an impersonation
  primitive, not an action.
- `TrafficController::collect` — the visitor beacon; agents do not fabricate traffic.
- `WooController::serve`/`servePath` — static SPA delivery, not an action.

## Decisions

### Decision 1: All writes are curated, none derived

A derived `update` verb applies whatever arrives. The real write paths re-verify invariants
(`PortalObjectWriter` re-stamps scope fields; region updates resolve through
`PortalRegionResolver`; revocation is an admin-only service path). Curated tools call those paths,
so a tool cannot do what the UI could not. Side effect: the derived catalog keeps
`portaliq-mcp-adoption`'s "no derived tool id ends in `.create`/`.update`/`.delete`" property —
that scenario survives verbatim with the qualifier *derived*.

### Decision 2: Every curated tool declares hints — the ping lesson, inverted

`portaliq-mcp-adoption`'s motivation records that hermiq classifies a hint-less 2-segment id as
write/destructive (fail closed). Correct — and it means an unannotated `portaliq.trafficSummary`
would be grant-locked as if it could destroy something. So annotations are mandatory per tool:
reads carry `readOnlyHint: true` + `scope: 'read'`; writes carry their true scope, their reach,
and `destructiveHint` where deserved (`revokeOrganisationSessions`). Hermiq treats these as
restrict-only signals; server-side authorization is unchanged by them.

### Decision 3: Approval gates are declared where the blast radius is external or instance-visible

Three tools change what an external person experiences (`publishPortalPage` — the public portal;
`sendPortalMessage` — a citizen's legal-effect inbox, cf. `portalMessage.rechtsgevolg`;
`revokeOrganisationSessions` — every user of an organisation logged out). Those require a human in
the loop via `human-approval-gate`. Draft-state edits (`updatePortalContent`, `updatePageRegions`,
`applyTheme`) stay default-denied but approval-free once granted: they change nothing a visitor
sees until `publishPortalPage` — the gate sits at the moment of irreversibility.

### Decision 4: Portaliq audits its own side

hermiq's `run-audit-log` records what the agent did from the agent's perspective; it cannot see a
portaliq internals-level fact. Every tool execution therefore writes a `portalAuditEntry` through
the existing `AuditTrailService` (fact-only by design: verb, target register/schema/id, timestamp
— never payload; `record()` never throws, so an audit outage cannot fail the action, and a denied
invocation is the governor's record, not portaliq's).

## Chat, concretely — the scenarios the surface must serve

1. *"Which submissions failed delivery today?"* → `portaliq.portalsubmission.search`
   (`deliveryStatus`, date-bounded). Read grant only; answerable with zero approval friction.
2. *"Supplier Jansen BV reports a stolen laptop — revoke all their portal sessions."* →
   `portaliq.revokeOrganisationSessions` (delete × instance, default-deny): the agent proposes,
   `human-approval-gate` shows a human "revoke N sessions for organisation X", execution follows
   approval, `portalAuditEntry` records it.
3. *"Publish the new opening-hours page on the citizen portal."* → drafts via
   `portaliq.updatePortalContent` (granted, no approval), then `portaliq.publishPortalPage` stops
   at the approval gate; the operator approves and the page goes live at the edge.

## Nextcloud Integration

- DI: `PortaliqToolProvider` registered in `lib/AppInfo/Application.php` against
  `OCA\OpenRegister\Mcp\IMcpToolProvider` — a governed successor to the alias the
  `portaliq-mcp-adoption` change removes.
- Derived reads execute through OpenRegister `ObjectService` (RBAC-enforcing, same as the UI).
- Curated writes execute through the named portaliq services; admin-gated paths
  (`revokeOrganisationSessions`) re-check admin authority server-side per ADR-005 — the tool layer
  adds no bypass.

## Security Considerations

- **Edge unreachability is the load-bearing wall** (ADR-046): MCP transport lives on the internal
  Nextcloud surface only. No route under `/portal/`, nothing `#[PublicPage]`, nothing behind
  `PortalAuthMiddleware`/`PortalProtected` serves MCP; a valid portal bearer presented to any MCP
  path is rejected as unauthenticated. Tested with a real bearer, not asserted by reading the
  routing table.
- **Net exposure of reads:** `portalSubmission.payloadCopy` and `portalMessage.dataCopy`/`body`
  are subject-scoped through OR RBAC; `portalAuditEntry` and `portalNotification` are fact/state
  records with no payload fields by construction (verified property lists above).
- **Identity/session schemas stay off** — inherited requirement, re-asserted by test here because
  this change is the first to add write tools near them.

## File Structure

```
lib/
  Mcp/PortaliqToolProvider.php        ← NEW: curated writes + trafficSummary, annotated
  AppInfo/Application.php             ← provider registration
  Settings/portaliq_register.json     ← read dialects: portalSubmission, portalAuditEntry, portalNotification
tests/Unit/Mcp/                       ← catalog shape, annotations, edge-unreachability, audit pin
```

## Trade-offs

Eleven read tools + seven curated tools is a real surface where `portaliq-mcp-adoption` shipped
two — the growth is bounded by the rule that every tool names its production code path, and the
things that stay off (identity, sessions-as-objects, message updates, auth ceremonies) are named
with reasons rather than left to future drift.

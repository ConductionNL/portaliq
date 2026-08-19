---
kind: code
---

# Proposal: hermiq-ai-tooling

## Summary

**Extends `portaliq-mcp-adoption` — it does not replace or repeat it.** That change (see
`openspec/changes/portaliq-mcp-adoption/`) deletes the app-template scaffold and gives portaliq a
two-tool read surface on the portal inbox
(`specs/portaliq-mcp-surface/spec.md#requirement-a-curated-read-only-dialect-on-the-portal-inbox`).
This change takes the next step the product framing asks for: **every internal staff action
portaliq offers becomes an MCP tool**, so any of them can in principle be automated by an AI agent
— and every one of them is governed by hermiq's per-agent grant model (scope
`read`/`create`/`update`/`delete` × reach `self`/`user`/`instance`/`external`, default-deny
writes, human approval gates, audit trail). Even without automation, this is what makes chat a way
of commanding the app: an operator chats while portaliq executes.

Two hard lines are stated normatively, not as intentions: writes are **curated tools only** (the
schema-derived catalog stays read-only, preserving `portaliq-mcp-adoption`'s
`#requirement-a-curated-read-only-dialect-on-the-portal-inbox` reasoning), and MCP tools serve
**internal staff and their agents only** — the surface is never reachable from, or exposed at, the
external portal edge (ADR-046).

## Motivation

**Portaliq's staff actions exist and none is agent-invocable.** Enumerated from the real
controllers and services (not imagined):

- **Session administration** — `SessionAdminController::revokeOrganisation` (instance-admin-only
  incident response: revoke every `portalSession` for an organisation).
- **CMS / page management** — the `portal`, `page`, `menu`, `glossaryTerm` schemas edited through
  the internal UI; region composition via `PageRegionsController::update`; publishing controlled
  by `portal.status` / `page` lifecycle; theming via `ThemeController` +
  `PortalThemeResolver`/`PortalTokenCss`.
- **Contribution/submission handling** — `portalSubmission` records written by
  `SubmissionReceiptService` (WMEBV proof of receipt, `deliveryStatus`), delivery bookkeeping in
  `portalNotification` (`status`, `attempts`, `lastAttemptAt` — `NotificationDispatchService`).
- **Messaging** — staff-originated `portalMessage` objects to a subject (the write half of the
  inbox whose read half `portaliq-mcp-adoption` already tools).
- **Traffic/analytics** — `TrafficController::summary` / `PortalTrafficService` aggregates.
- **Audit** — `portalAuditEntry` written by `AuditTrailService` (fact-only: `jti`, `subjectRef`,
  `organisation`, `appId`, `verb`, `register`, `schema`, `targetId`, `timestamp`).
- **WOO publication** — the WOO SPA (`WooController`) publishes what the CMS content decides;
  toolable surface is the content lifecycle above, not the static server.

An operator who wants "revoke everything for org X, their laptop was stolen" or "publish the new
opening-hours page" today clicks through the admin UI. With a governed tool surface, they say it —
and hermiq's `agent-tool-governance` (default-deny for write/destructive), `human-approval-gate`
and `run-audit-log` capabilities decide whether an agent may actually do it, and record that it
did.

**Why now, and why on top of `portaliq-mcp-adoption`:** that change is deliberately minimal (its
own design calls the two-tool result "the smallest MCP surface in the fleet — proportionate"). It
is the right floor and the wrong ceiling: it predates the fleet decision that every app exposes
its actions for agent use. This change keeps its floor intact — the scaffold stays dead, the
identity/session schemas stay off (`#requirement-portal-identity-and-session-objects-are-not-tools`
is untouched and reaffirmed) — and builds the action surface above it.

## Affected Projects

- [ ] Project: `portaliq` — read dialects on three operational schemas; one curated `PortaliqToolProvider` carrying the governed non-CRUD/write tools; no portal-edge change.

## Scope

### In Scope

- `x-openregister-mcp` **read-only** (`search` + `get`, `scope: 'read'`) on `portalSubmission`,
  `portalAuditEntry` and `portalNotification` — extending the pattern `portaliq-mcp-adoption`
  establishes on `portalMessage`.
- One curated provider, `lib/Mcp/PortaliqToolProvider.php` (`OCA\OpenRegister\Mcp\IMcpToolProvider`,
  tool ids `portaliq.{toolName}`), carrying the enumerated write and non-CRUD tools, each annotated
  with hermiq's `scope` × `reach` and appropriate hints.
- Human-approval gating declared for the high-impact writes: publishing a portal page,
  organisation-wide session revocation, sending a message to an external subject.
- An audit record (`portalAuditEntry` via `AuditTrailService`) for every tool invocation.
- The normative ADR-046 requirement: no MCP reachability at the portal edge.

### Out of Scope

- **`portalAccount` and `portalSession` as tools** — remains forbidden by `portaliq-mcp-adoption`
  (`#requirement-portal-identity-and-session-objects-are-not-tools`); this change adds nothing
  there and depends on that requirement staying in force. (`revokeOrganisationSessions` acts on
  sessions without reading or returning them — see design.)
- Derived (schema-declared) **write** verbs — every write is curated; the derived catalog stays
  read-only.
- `update`/`delete` on `portalMessage` — the mark-read hazard argued in `portaliq-mcp-adoption`
  stands unchanged.
- hermiq-side changes — the grant model, approval gate and audit log are hermiq capabilities
  (`agent-tool-governance`, `human-approval-gate`, `run-audit-log`); portaliq only annotates its
  catalog so they can do their job.
- Visitor-facing AI of any kind. Portal visitors are not Nextcloud users (ADR-046) and get no
  agent surface from this change.

## Approach

Two of ADR-063's three supply chains, deliberately split by mutability: **chain 1**
(schema-declared CRUD) carries all reads; **chain 3** (hand-written `IMcpToolProvider`) carries
all writes and genuine non-CRUD reads (traffic summary), because a write tool needs argument
validation, invariant re-verification and approval metadata that a derived CRUD verb cannot
express. This **modifies** `portaliq-mcp-adoption`'s
`#requirement-portaliq-exposes-no-hand-written-mcp-tool` from "no hand-written provider" to
"exactly one governed provider, scaffold still banned" — recorded as a MODIFIED requirement in
this change's delta spec, since that requirement was written before the fleet's
all-actions-toolable decision.

## New Dependencies

None. OpenRegister `SchemaDerivedToolProvider` + `McpAnnotationValidator` (already required by
`portaliq-mcp-adoption`); hermiq consumes the catalog, portaliq does not depend on hermiq.

## Impact

- `lib/Settings/portaliq_register.json` — `x-openregister-mcp` read blocks on `portalSubmission`,
  `portalAuditEntry`, `portalNotification`.
- `lib/Mcp/PortaliqToolProvider.php` — **new** (the directory was emptied by
  `portaliq-mcp-adoption`; this is a governed provider, not the scaffold's return).
- `lib/AppInfo/Application.php` — provider registration.
- `lib/Service/AuditTrailService.php` — a `record()` call site per tool execution path (service
  itself unchanged).
- `tests/Unit/Mcp/` — provider tests incl. edge-unreachability and grant-annotation pins.

## Cross-Project Dependencies

- **`portaliq-mcp-adoption` MUST land first** — this change extends its capability spec
  (`portaliq-mcp-surface`) and assumes the scaffold is gone and `portalMessage` reads exist.
- hermiq — `agent-tool-governance` (union rule: hints/verb-suffix classification plus reach;
  fail-closed `external` when no reach is declared), `agent-capability-reach`,
  `human-approval-gate`, `run-audit-log`. Portaliq must annotate every curated tool because
  hermiq classifies a hint-less 2-segment id as write/destructive and an undeclared reach as
  `external` — correct fail-closed behaviour that would otherwise bury portaliq's read tools
  behind grants.
- ADR-046 (hydra) — the boundary; ADR-063 — the tool-supply chains; ADR-005 — fail-closed.

## Risks

### Risk 1: A write tool becomes reachable from the external portal edge

**Severity:** High — **Mitigation:** Structural, stated as a hard SHALL: no route under
`/portal/`, no `#[PublicPage]` controller and no `PortalAuthMiddleware`-authenticated path serves
MCP; tool execution requires an internal Nextcloud identity, and a portal bearer
(`PortalSessionService`) is never such an identity. A test drives the tool endpoint with a valid
portal bearer and asserts rejection — proving the guard can fail before trusting it.

### Risk 2: An agent quietly performs a high-impact act (publish, mass-revoke, message a citizen)

**Severity:** High — **Mitigation:** Default-deny on every write (hermiq classifies them so from
the declared scope and from the fail-closed hint rules), plus explicit `approval: required`
metadata on the three high-impact tools so `human-approval-gate` interposes a human. Annotations
are advisory UX per hermiq's governance spec — authorization stays OpenRegister RBAC + Nextcloud
admin checks server-side, so a tampered annotation widens nothing.

### Risk 3: The audit trail becomes a second copy of the data

**Severity:** Medium — **Mitigation:** `AuditTrailService` records facts only (verb + target ids,
never payload) by design; tool invocations reuse it unchanged, and the read dialect on
`portalAuditEntry` therefore exposes no payload either.

### Risk 4: Read-dialect filters drift from schema properties

**Severity:** Low — **Mitigation:** OpenRegister's `McpAnnotationValidator::validateFilters()`
rejects a filter naming a non-property at import; every filter below names a verified property.

## Rollback Strategy

Revert the register JSON hunk (derived dialects disappear; `portalMessage` reads from
`portaliq-mcp-adoption` remain). Revert the provider commit (curated tools disappear; DI
registration goes with it). Each is independently revertible; neither touches portal-edge code.

## Open Questions

- Should approval requirements be expressed as a shared fleet annotation key on the descriptor
  (consumable by any governor, not only hermiq), or remain hermiq-configured per tool id? This
  change declares them on the descriptor and lets hermiq treat them as restrict-only signals.
- `portaliq.sendPortalMessage` reaches an external person (`reach: external`). Is per-message
  approval right, or should a standing grant allow template-bound messages (e.g. receipt resends)?
  Spec'd conservatively as approval-required; loosening is a spec change, not a config drift.

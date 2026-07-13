---
kind: code
---

# Proposal: portaliq-mcp-adoption

## Summary

Portaliq's `lib/Mcp/ExampleToolProvider.php` is **unmodified app-template scaffold** — it was checked
in by the commit literally titled *"Initial Portaliq scaffold from nextcloud-app-template"* and still
exposes the template's two teaching tools, `portaliq.ping` (echoes a string) and `portaliq.describeApp`
(returns the app's own version from `info.xml`). There is no migration to perform here, because there
is nothing real to migrate. The honest change is: **delete the class and its alias**, and declare the
ADR-063 dialect on the one schema of Portaliq's four that an agent has any business reading —
`portalMessage`.

## Motivation

**The provider is cruft, and cruft on the MCP surface is not free.** Two dead tools still enumerate in
every `portaliq` tool catalog, consuming prompt tokens and tool-selection attention for zero
capability. `portaliq.ping` is an echo. `portaliq.describeApp` returns a version string. Worse, both
are hint-less 2-segment ids, so a consumer that fails closed on unclassifiable tools (as hermiq now
does, per `hermiq#57`) classifies **an echo function as write/destructive**. That is the whole cost of
the scaffold, paid forever, for nothing.

**And Portaliq has no real surface.** None of its four schemas carries `x-openregister-mcp`, so
`SchemaDerivedToolProvider` emits nothing. A portal user asking "do I have any new messages?" — the
single most obvious question to ask a portal — cannot be answered.

It is worth stating plainly rather than dressing this up as a migration: the brief anticipated
"3 tools" to classify and move. There are **two**, both are template teaching examples, and the right
disposition for both is `rm`.

## Affected Projects

- [ ] Project: `portaliq` — `ExampleToolProvider` and its alias deleted; `x-openregister-mcp` declared read-only on `portalMessage`.

## Scope

### In Scope

- Delete `lib/Mcp/ExampleToolProvider.php` and the `IMcpToolProvider::portaliq` alias registration in `lib/AppInfo/Application.php`.
- Declare `x-openregister-mcp` on `portalMessage` with `search` + `get` only, `scope: 'read'`.

### Out of Scope

- **`portalAccount` and `portalSession`** — deliberately and permanently off the tool surface; see Risk 1.
- Any write verb. An agent marking a portal message read is a small write with no upside and a real downside (it can hide an unread message from the user).
- No `IMcpScannableServices` opt-in: after the deletion Portaliq has **zero** genuine non-CRUD tools, so an opt-in listing no services would be an empty seam.
- Cleaning up the `exampleDocument` schema itself (also template-derived) — a separate concern.

## Approach

Chain 1 (schema-declared CRUD) only. Nothing hand-written survives, so chains 2 and 3 are unused.

## New Dependencies

None.

## Impact

- `lib/Mcp/ExampleToolProvider.php` — **deleted** (221 lines, all scaffold).
- `lib/AppInfo/Application.php` — alias registration removed (lines 76–81, including the comment block that instructs the reader to keep it).
- `lib/Settings/portaliq_register.json` — `x-openregister-mcp` on `portalMessage`.
- `tests/` — any `ExampleToolProvider` fixture removed.

## Cross-Project Dependencies

Depends on OpenRegister `origin/development` for `SchemaDerivedToolProvider` and
`McpAnnotationValidator`. No OpenRegister change required.

**Fleet note:** this scaffold ships in `nextcloud-app-template`, so every app generated from it
carries the same two dead tools and the same "keep this alias" comment. Fixing Portaliq does not fix
the template. Worth a follow-up — see Open Questions.

## Risks

### Risk 1: Exposing portal identity or session objects would leak PII and credential material

**Severity:** High — **Mitigation:** Both stay off, permanently, and this is recorded normatively in
the spec so a later dialect edit cannot quietly re-open it.

- `portalAccount` carries `claims` — the raw IdP claim blob — plus `email`, `identityRef` and
  `subjectRef`. The dialect has **no field projection**: a `get` returns the whole object, so there is
  no way to expose "account status" without also dumping the identity claims into an LLM prompt.
- `portalSession` carries `jti`, `trustLevel`, `expiresAt` and `revoked` — live session-token
  metadata. Enumerating a user's live sessions is an attacker's reconnaissance step, and no human has
  ever asked an assistant about it in prose.

### Risk 2: The derived read must not widen who can see a portal message

**Severity:** Medium — **Mitigation:** Portal access is subject-scoped (`subjectRef` / `organisation`)
and enforced by OpenRegister RBAC through `ObjectService`, which the derived provider invokes — the
same gate the existing UI reads through. The dialect adds no bypass; hints are advisory only. A task
verifies that `portaliq.portalmessage.search` cannot return another subject's messages.

## Rollback Strategy

Revert the JSON hunk to drop the dialect (an app with no opted-in schema derives no tools). Revert the
PHP commit to restore the scaffold class — though there is no reason anyone would want to.

## Open Questions

- `nextcloud-app-template` still ships `ExampleToolProvider` and the alias-registration comment that
  tells every new app to keep it. Should the template drop it in favour of an `x-openregister-mcp`
  example? Every app scaffolded from it inherits this exact debt.
- `exampleDocument` is the template's reference content type, wired into `PortalContributionProvider`
  to exercise the write-IDOR-safe update path. Is it a real Portaliq noun, or should it be renamed to
  the domain object it stands in for?

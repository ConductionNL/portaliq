# portaliq-mcp-surface Specification

**Status**: planned
**Scope**: portaliq
**OpenSpec changes**:
- `portaliq-mcp-adoption` — deletes the app-template scaffold provider and declares the ADR-063 read-only dialect on `portalMessage` (kind: code)

## Purpose

Defines Portaliq's agent-facing MCP tool surface under ADR-063: a single read-only pair over the
portal inbox, and a normative refusal of the identity and session objects. Portaliq's previous
"surface" was unmodified `nextcloud-app-template` scaffolding (`ping`, `describeApp`) that shipped
capability nothing and cost tokens something; this capability replaces it with the one thing a portal
user would actually ask.

## ADDED Requirements

### Requirement: Portaliq exposes no hand-written MCP tool
The system MUST NOT register any `IMcpToolProvider` implementation for app id `portaliq`, and MUST NOT annotate any Portaliq service method with `#[McpTool]`.

`lib/Mcp/ExampleToolProvider.php` MUST be deleted, and the
`OCA\OpenRegister\Mcp\IMcpToolProvider::portaliq` alias registration MUST be removed from
`lib/AppInfo/Application.php`. The class is unmodified app-template scaffold: its two tools
(`portaliq.ping`, `portaliq.describeApp`) echo a string and return the app's own version, touching no
Portaliq object. An empty provider MUST NOT be left behind in its place — per ADR-063, a provider with
no tools is deleted, not retained as a seam.

#### Scenario: The scaffold tools no longer enumerate
- GIVEN OpenRegister builds the tool catalog for app id `portaliq`
- WHEN an MCP client lists available tools
- THEN neither `portaliq.ping` nor `portaliq.describeApp` MUST appear
- AND every returned tool id MUST match the 3-segment derived form `portaliq.{schema}.{verb}`

### Requirement: A curated read-only dialect on the portal inbox
The system MUST declare `x-openregister-mcp` on `portalMessage` with the `search` and `get` verbs only, `scope: 'read'`, `readOnlyHint: true`, and `search.filters` naming only real declared properties of the schema.

The declaration MUST NOT enable `create`, `update` or `delete`. An `update` would let an agent set
`read: true` and thereby hide an unread message from its recipient — a small write with no upside and
a real downside.

#### Scenario: A portal user can ask about their messages
- GIVEN `portalMessage` declares the dialect with filters `read`, `organisation`, `subjectRef`
- WHEN the derived catalog for `portaliq` is built
- THEN it MUST emit exactly `portaliq.portalmessage.search` and `portaliq.portalmessage.get`
- AND both MUST carry `readOnlyHint: true` and `scope: 'read'`

#### Scenario: No write tool is derivable for Portaliq
- GIVEN every `x-openregister-mcp` block in `portaliq_register.json`
- WHEN the derived catalog is built
- THEN it MUST contain no tool id ending in `.create`, `.update` or `.delete`

#### Scenario: A message read cannot cross subjects
- GIVEN portal access is scoped by `subjectRef` and `organisation`
- WHEN `portaliq.portalmessage.search` is invoked on behalf of subject S
- THEN OpenRegister RBAC via `ObjectService` MUST restrict results to S's own messages
- AND no message belonging to another subject MUST be returned

### Requirement: Portal identity and session objects are not tools
The system MUST NOT declare `x-openregister-mcp` on `portalAccount`, `portalSession` or `exampleDocument` — for read verbs as well as write verbs.

`portalAccount` carries the raw IdP `claims` blob alongside `email` and `identityRef`; `portalSession`
carries live session-token metadata (`jti`, `trustLevel`, `expiresAt`, `revoked`). Because the dialect
has no field projection, a `get` returns the entire object — so "expose the status, not the claims" is
not expressible, and the schema MUST stay off rather than leak identity or session material into an
LLM prompt. `exampleDocument` is the app template's reference content type, not a domain noun.

#### Scenario: Identity and session objects are unreachable
- GIVEN the derived catalog for app id `portaliq`
- WHEN an agent enumerates available tools
- THEN no `portaliq.portalaccount.*` and no `portaliq.portalsession.*` tool MUST appear
- AND no tool returning an IdP claims blob or a session `jti` MUST exist

## Non-Functional Requirements

- **Performance:** The `portaliq` catalog MUST contain only the two derived read tools — no scaffold tool may consume tool-selection attention or prompt tokens (ADR-063 rule 3).
- **Internationalization:** No new user-facing strings; tool `description` prose is agent-facing English. N/A for `nl_NL`/`en_US`.

## Acceptance Criteria

- [ ] `lib/Mcp/ExampleToolProvider.php` no longer exists and no `IMcpToolProvider::portaliq` alias is registered
- [ ] The `portaliq` catalog contains exactly two derived read tools
- [ ] No `create`/`update`/`delete` tool id exists for `portaliq`
- [ ] `portalAccount`, `portalSession` and `exampleDocument` carry no `x-openregister-mcp` block

## Notes

- ADR-063 (hydra#102). Verified at OpenRegister `origin/development`:
  `Mcp/BuiltIn/SchemaDerivedToolProvider.php` and `Service/Mcp/McpAnnotationValidator.php`.
- The scaffold ships in `nextcloud-app-template`, so every app generated from it carries the same two
  dead tools and the same "keep this alias" comment. Fixing Portaliq does not fix the template.

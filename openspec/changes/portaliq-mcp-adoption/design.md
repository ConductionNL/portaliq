# Design: portaliq-mcp-adoption

## Architecture Overview

After this change Portaliq uses exactly one of ADR-063's three tool-supply chains:

1. **Schema-declared CRUD** — `x-openregister-mcp` in `lib/Settings/portaliq_register.json`;
   `SchemaDerivedToolProvider` emits `portaliq.portalmessage.{search,get}`.
2. **`#[McpTool]` on services** — unused. Portaliq has no genuine non-CRUD agent behaviour.
3. **Hand-written `IMcpToolProvider`** — **eliminated.** `ExampleToolProvider` is deleted.

## Evidence that the provider is scaffold, not product

Stated explicitly because the disposition (delete, don't migrate) rests entirely on it:

- `git log lib/Mcp/ExampleToolProvider.php` → introduced by **`0fa52bf "Initial Portaliq scaffold from nextcloud-app-template"`**. The only later commit (`38597b2`) is the fleet-wide `AbstractToolHandler` refactor, which touched every app's provider mechanically.
- The class is still **named `ExampleToolProvider`**, not `PortaliqToolProvider`.
- Its docblock still says *"This is teaching scaffolding"*, *"Minimal, heavily-commented example"*, and *"replace the two example tools with real ones"*.
- Its `TOOL_DESCRIPTORS` are still annotated with the template's own instructions — `// ← edit this: '{appId}.{toolName}'`.
- The two tools are `ping` (returns `['ok' => true, 'echo' => $message]`) and `describeApp` (returns `$appManager->getAppInfo('portaliq')`). Neither touches a Portaliq object, a portal, a session or a message.

The brief's expectation of "3 tools" is off by one: there are **two**, and both are the template's.

## Curation table — 1 of 4 schemas ON, read-only

`filters` cross-checked against the real `properties` in `portaliq_register.json`;
OpenRegister's `McpAnnotationValidator::validateFilters()` rejects a schema at import if a filter
names a non-property.

### ON

| Schema | Verbs | Filters (verified real properties) | One-line justification |
|---|---|---|---|
| `portalMessage` | `search`, `get` | `read`, `organisation`, `subjectRef` | The portal's inbox — *"do I have new messages / what did the municipality send me?"* is the single most obvious question to ask a portal, and reading one's own messages mutates nothing. |

**No write verb.** `update` would let an agent flip `read: true` and thereby hide an unread message
from the user it was sent to. Small write, no upside, real downside.

### OFF — and why

| Schema | Why OFF |
|---|---|
| `portalAccount` | Carries the raw IdP **`claims`** blob plus `email` / `identityRef` / `subjectRef`. The dialect has no field projection — a `get` returns the whole object — so "expose account status only" is not expressible. Identity claims must not land in an LLM prompt by default. |
| `portalSession` | **Session/credential metadata**: `jti`, `trustLevel`, `expiresAt`, `revoked`. Enumerating live sessions is reconnaissance, not a user question. |
| `exampleDocument` | The template's reference content type, wired into `PortalContributionProvider` to exercise the write-IDOR-safe update path. It is scaffolding with a schema, not a domain noun. |

One schema ON of four is far below the ADR-063 "5–15" band, and that is the honest answer rather than
a shortfall: Portaliq only *has* four schemas, two are identity/session objects that must never be
tools, and one is template scaffold. The band describes a well-populated app; Portaliq is a thin
portal with one user-facing noun. Padding the surface to hit a number would be exactly the tool
explosion rule 3 forbids.

## Surgery classification — both hand-written tools

| Tool id | Verdict | Disposition |
|---|---|---|
| `portaliq.ping` | **Neither CRUD nor genuine non-CRUD — template scaffold.** Returns a static echo; touches nothing. | **DELETE.** Not moved to a service: there is no behaviour to preserve. |
| `portaliq.describeApp` | **Template scaffold.** Returns `info.xml` metadata for the app itself. | **DELETE.** An agent has no use for Portaliq's own version string, and OpenRegister's built-in providers already cover app/register introspection. |

The provider is then empty, so per ADR-063 the class is **deleted** rather than left as an empty seam.
The `IMcpToolProvider::portaliq` alias goes with it — an alias pointing at a deleted class is a fatal
DI error, and an alias pointing at an empty provider is a trap for the next reader.

## Decisions

### Decision 1: delete, don't migrate

Alternative considered: keep the provider and "migrate" `describeApp` to a service with `#[McpTool]`.
Rejected — it would dress up scaffold as product. There is no requirement anywhere in Portaliq's specs
that an agent be able to echo a string or read the app's version. Writing a migration task for code
nobody asked for is how scaffolding becomes permanent.

### Decision 2: no `IMcpScannableServices` opt-in

With zero surviving tools there is nothing to scan. Adding the interface "for later" would create the
same kind of empty seam this change is removing.

## Nextcloud Integration

- Services: none added.
- DI: the `OCA\OpenRegister\Mcp\IMcpToolProvider::portaliq` alias registration is **removed** from
  `lib/AppInfo/Application.php` (together with the comment block that instructs the reader to keep it).
- Reads are served by OpenRegister's `SchemaDerivedToolProvider` via `ObjectService` — the same
  RBAC-enforcing path the Portaliq UI already reads through.

## Security Considerations

Net reduction. Two dead tools leave the catalog; two RBAC-gated read tools enter it. Portal access is
subject-scoped (`subjectRef` / `organisation`) and enforced by OpenRegister RBAC inside
`ObjectService`, which the derived provider calls — the dialect adds no bypass, and declared hints are
advisory metadata for classification and UX only. Every derived invocation additionally writes an
immutable audit record, which the scaffold provider did not. The two identity-bearing schemas
(`portalAccount`, `portalSession`) are refused at declaration time, which is a stronger guarantee than
refusing them at invoke time.

## File Structure

```
lib/
  Mcp/
    ExampleToolProvider.php   ← DELETED (directory becomes empty and is removed)
  AppInfo/Application.php     ← IMcpToolProvider::portaliq alias REMOVED
  Settings/
    portaliq_register.json    ← x-openregister-mcp on portalMessage (search, get)
```

## Trade-offs

Portaliq ends up with the smallest MCP surface in the fleet: two tools. That is proportionate — it is a
portal with one user-facing noun, and the alternative (exposing accounts and sessions to pad the
catalog) is precisely the thing that must not happen.

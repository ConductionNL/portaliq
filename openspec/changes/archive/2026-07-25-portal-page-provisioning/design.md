# Design: portal-page-provisioning

## Architecture Overview

No new architecture — this reuses the four proven contract-v2/v3 seams
(`contract-v2/design.md`, `contribution-manifest-v3`) and adds one new data
source (`portalPage` objects) plus one new authorisation mode (anonymous)
alongside the existing PHP-provider / bearer-subject path:

```
Discovery (unchanged):
  PortalContributionRegistry.aggregateFor(subject)      — bearer-authenticated
  PortalContributionRegistry.aggregateAnonymous()        — NEW, no subject
        │                                                       │
        ▼                                                       ▼
  every installed app's OCA\{App}\Portal\PortalContributionProvider
        │
        ├─ PHP providers (procest, pipelinq, …) — unchanged, hardcoded manifest
        │
        └─ OCA\Portaliq\Portal\PortalContributionProvider (built-in, THIS change)
              reads ACTIVE `portalPage` objects (PortalObjectReader)
              → converts each to the manifest shape
              → same PortalManifestNormaliser every provider's output passes through

Write (create, unchanged shape, new anonymous branch):
  ContributionController.create()
    subject resolved  → authorisedCreateAction()          → PortalObjectWriter.createObject()      (owned)
    subject NULL       → authorisedAnonymousCreateAction() → PortalObjectWriter.createAnonymousObject() (unowned)
```

Key property, unchanged from contract-v2: every write authorises against the
output of an aggregation path (`aggregateFor()` or, new, `aggregateAnonymous()`)
— never against the client's own claim about what it may do. The built-in
provider is just another producer feeding that same aggregation; it gets no
special authority.

## The `portalPage` schema

One `portalPage` object = one data-provisioned contribution (the same unit a
PHP provider's single `getContribution()` call returns). Its `pages[]`
property is the set of citizen-facing UI pages that contribution composes —
mirrors `IPortalContributionProvider`'s documented manifest exactly, plus one
new field (`anonymous`) this change introduces on collections/actions.

```json
{
  "portalPage": {
    "slug": "portalPage",
    "icon": "WebBox",
    "version": "0.1.0",
    "title": "Portal Page",
    "description": "A data-provisioned portal contribution (ADR-046). Mirrors the IPortalContributionProvider manifest shape 1:1 so Portaliq's built-in provider can read this object and hand it to the same PortalManifestNormaliser every PHP provider's output passes through. Owned by the app that provisions the page; never publicly readable — the built-in provider is the only reader, server-side.",
    "type": "object",
    "required": ["label", "audience", "status"],
    "x-openregister": { "publicRead": false, "publicWrite": false },
    "properties": {
      "label": {
        "title": "Label",
        "type": "string",
        "description": "Display name for this contribution in the portal SPA (nav / page title).",
        "example": "Meldingen"
      },
      "audience": {
        "title": "Audience",
        "type": "string",
        "description": "Which external audience this contribution serves. Open string set (contract v2 A2), same vocabulary as portalAccount.audience — e.g. supplier, client, citizen. Not enum-constrained.",
        "example": "citizen"
      },
      "minTrust": {
        "title": "Minimum trust",
        "type": "string",
        "description": "Contribution-level default minimum trust (low|substantial|high, contract v2 A3). Absent defaults to low. Individual collections/actions may override with their own minTrust. Mutually exclusive with an entry's own `anonymous: true` — see the Anonymous vs minTrust decision below.",
        "enum": ["low", "substantial", "high"],
        "example": "low"
      },
      "status": {
        "title": "Status",
        "type": "string",
        "description": "Lifecycle status. Only `active` portalPage objects are read into any aggregate (authenticated or anonymous); `draft` lets an author stage a page without exposing it.",
        "enum": ["active", "draft"],
        "example": "active"
      },
      "collections": {
        "title": "Collections",
        "type": "array",
        "description": "Readable OpenRegister collections this contribution exposes. Each entry mirrors IPortalContributionProvider's collection shape (contract v2/v3).",
        "items": {
          "type": "object",
          "required": ["id", "register", "schema"],
          "properties": {
            "id": { "title": "Collection id", "type": "string", "description": "Unique within this contribution; referenced by pages[].blocks[].collection.", "example": "meldingen" },
            "register": { "title": "Register", "type": "string", "description": "The OpenRegister register slug the collection reads from. A plain string, not an OR object relation — this selects a (register, schema) pair, it does not reference a specific object instance (ADR-062's $ref/format:uuid relation dialect applies to same-register object foreign keys, not cross-register schema selectors).", "example": "openbuild" },
            "schema": { "title": "Schema", "type": "string", "description": "The schema slug within that register.", "example": "melding" },
            "scopeField": { "title": "Scope field", "type": "string", "description": "The property rows are scoped by (contract v2). Omit or leave absent on an anonymous collection — there is no subject to scope by.", "example": "subjectRef" },
            "scopeClaim": { "title": "Scope claim", "type": "string", "description": "Contract v2 A4: scope by a server-managed portalAccount claim instead of scopeField/subjectRef. Never applicable to an anonymous entry (no portalAccount)." },
            "via": { "title": "Via (reverse/forward join)", "type": "object", "description": "Contract v2 A5/A5.2: one-hop join scoping. See portal-contribution-contract spec for the full shape." },
            "minTrust": { "title": "Minimum trust", "type": "string", "enum": ["low", "substantial", "high"], "description": "Overrides the contribution-level minTrust for this collection." },
            "anonymous": { "title": "Anonymous", "type": "boolean", "description": "When true, this collection is included in aggregateAnonymous() and readable with no bearer session. Mutually exclusive with a non-low minTrust on the SAME entry (fail-closed: normaliser drops anonymous when both are set). Default false.", "example": false },
            "fields": { "title": "Field projection", "type": "array", "description": "Read-side whitelist (field-projection change); absent returns the full row.", "items": { "type": "string" } },
            "columns": { "title": "Columns", "type": "array", "description": "Presentation-only per-column render hints (contribution-manifest-v3)." },
            "detail": { "title": "Detail layout", "type": "object", "description": "Presentation-only detail-view layout (contribution-manifest-v3)." },
            "listable": { "title": "Listable", "type": "boolean", "description": "Whether this collection gets a synthesised default page when pages[] is absent.", "example": true }
          }
        }
      },
      "actions": {
        "title": "Actions",
        "type": "array",
        "description": "Writable/forwardable actions this contribution exposes. Each entry mirrors IPortalContributionProvider's action shape (contract v2/v3), plus the new `anonymous` field.",
        "items": {
          "type": "object",
          "required": ["id", "type", "label"],
          "properties": {
            "id": { "title": "Action id", "type": "string", "description": "Unique within this contribution; referenced by pages[].blocks[].action and by POST /portal/api/actions/{appId}/{actionId} for endpoint actions.", "example": "meldenMaken" },
            "type": { "title": "Type", "type": "string", "enum": ["create", "update", "endpoint"], "description": "create/update write through OpenRegister via the portal's own writer; endpoint bearer-forwards to a domain app's own route (contract v2 A6). Anonymous submission is only meaningful for type=create.", "example": "create" },
            "label": { "title": "Label", "type": "string", "example": "Melding maken" },
            "register": { "title": "Register", "type": "string", "description": "Target register for create/update. Plain string schema-selector, not an OR relation — see the collections.register note.", "example": "openbuild" },
            "schema": { "title": "Schema", "type": "string", "example": "melding" },
            "fields": { "title": "Field whitelist", "type": "array", "description": "The ONLY client-supplied fields accepted; anything else in the request body is dropped server-side.", "items": { "type": "string" } },
            "set": { "title": "Server-forced values", "type": "object", "description": "Values the server stamps AFTER the whitelist, never client-choosable (e.g. a fixed status transition target)." },
            "defaults": { "title": "Defaults", "type": "object", "description": "Values stamped over the whitelisted payload server-side (e.g. a supertype discriminator). Applied after the whitelist so a client can never override them." },
            "endpoint": { "title": "Endpoint path", "type": "string", "description": "Instance-local absolute path for type=endpoint (contract v2 A6). Full URLs are rejected (SSRF guard)." },
            "method": { "title": "HTTP method", "type": "string", "enum": ["GET", "POST", "PUT", "PATCH", "DELETE"], "description": "For type=endpoint; defaults to POST." },
            "minTrust": { "title": "Minimum trust", "type": "string", "enum": ["low", "substantial", "high"], "description": "Overrides the contribution-level minTrust for this action." },
            "anonymous": { "title": "Anonymous", "type": "boolean", "description": "When true and type=create, this action is reachable via POST /portal/api/collections/{register}/{schema} with NO bearer session — the write is unowned (no subjectRef/organisation stamped). Mutually exclusive with a non-low minTrust on the SAME entry (fail-closed: normaliser drops anonymous when both are set, falling back to requiring authentication). Default false.", "example": true }
          }
        }
      },
      "pages": {
        "title": "Pages",
        "type": "array",
        "description": "Explicit UI page composition (contribution-manifest-v3). Absent synthesises one default page per listable collection.",
        "items": {
          "type": "object",
          "required": ["id", "label", "blocks"],
          "properties": {
            "id": { "title": "Page id", "type": "string", "example": "melding-maken" },
            "label": { "title": "Label", "type": "string", "example": "Melding maken" },
            "icon": { "title": "Icon", "type": "string", "description": "An MDI name resolvable by the shared icon registry.", "example": "AlertCircleOutline" },
            "blocks": {
              "title": "Blocks",
              "type": "array",
              "description": "Ordered UI blocks composing the page. type ∈ collection|action|detail|richText|cta (PortalManifestNormaliser::BLOCK_TYPES) — an unrecognised type or an unresolvable collection/action reference is dropped by the normaliser, never rendered as broken.",
              "items": {
                "type": "object",
                "required": ["type"],
                "properties": {
                  "type": { "type": "string", "enum": ["collection", "action", "detail", "richText", "cta"] },
                  "collection": { "type": "string", "description": "References collections[].id (for type=collection/detail)." },
                  "action": { "type": "string", "description": "References actions[].id (for type=action)." },
                  "markdown": { "type": "string", "description": "For type=richText." }
                }
              }
            }
          }
        }
      }
    }
  }
}
```

Every property carries `title` + `description` (ADR-011). `collections[]`/
`actions[]`/`pages[]`/`blocks[]` are modelled as JSON-Schema `array`/`object`
with nested `properties` rather than an opaque free-form object, so the
schema stays self-documenting and OR's own schema-driven form renderer can
present a `portalPage` object for editing without a bespoke UI — an admin (or
another app's install-time repair step) can create one through the standard
OR objects UI/API, exactly like any other registered object.

### Why no `$ref`/relation dialect (ADR-062 rule 7)

`collections[].register`/`schema` and `actions[].register`/`schema` are
**schema selectors** (which register+schema pair a collection reads or an
action writes to), not references to a specific object instance. ADR-062's
canonical relation dialect (`type: string`, `format: uuid`, `$ref:
<schemaKey>`, same register) governs same-register object foreign keys — it
does not apply here, exactly as it already doesn't apply to the identical
`register`/`schema` string pair on every existing PHP provider's
hardcoded action/collection entries (`lib/Portal/PortalContributionProvider.
php:125-126`, `:205-206`, etc.) or on contract-v2's `via.register`/`via.
schema`. `portalPage` keeps the same plain-string convention for parity with
every other manifest producer.

## The built-in provider mechanism

`OCA\Portaliq\Portal\PortalContributionProvider` (existing FQCN,
`lib/Portal/PortalContributionProvider.php`) becomes config-driven:

- `getAudiences(): array` — queries active `portalPage` objects (register
  `portaliq`, schema `portalPage`, `status: active`, `_rbac: false /
  _multitenancy: false` per the existing trusted-intermediary convention
  every portal reader already uses) via `PortalObjectReader`, returns the
  distinct set of `audience` values found. Empty when no `portalPage`
  objects exist yet — the class still returns `[]`, never null/throws
  (matches the fail-closed "provider degrades to nothing" contract every
  duck-typed provider already honours per `PortalContributionRegistry::
  aggregateFor()`'s try/catch at `:111-116`).
- `getContribution(array $subject): ?array` — reads active `portalPage`
  objects whose `audience === $subject['audience']`, converts the FIRST
  matching one 1:1 into the manifest array shape (`label`, `collections`,
  `actions`, `pages`) `PortalContributionRegistry::aggregateFor()` already
  expects from any provider. Multiple active `portalPage` objects for the
  SAME audience is a data-authoring concern (out of scope here — see Open
  Question OQ2); this change picks the first by object id and logs a
  warning on a collision, never merges (merging two independently-authored
  manifests could silently combine an unrelated app's field whitelist with
  another's — fail-closed means "pick one deterministically and complain",
  not "guess a merge").
- The registry's existing `try { $filtered = $this->normaliser->
  normalise(...) } catch` (`PortalContributionRegistry.php:130-134`) already
  wraps EVERY provider's output including this one — no change needed there.

For `aggregateAnonymous()` (new registry method), the same provider is
consulted but the caller passes no `$subject['audience']` filter — instead
every active `portalPage` object is read, and only its `collections`/
`actions` entries carrying `anonymous: true` survive into the returned
contribution (mirrors `filterByTrust()`'s shape exactly, filtering on a
different flag). PHP providers participate in `aggregateAnonymous()` too
(duck-typed, since `anonymous` is now part of the optional v2/v3-style
vocabulary) — a domain app's own hardcoded provider can flag one action
anonymous without touching `portalPage` at all.

## Anonymous submission (fail-closed, additive)

**Decision: per-entry `anonymous` flag, not a contribution-level shortcut.**
`anonymous: true` is set on an individual collection/action, never inherited
from the contribution's `minTrust`/audience. A contribution mixing a private,
subject-scoped collection with one public intake action is common (e.g. "see
your OWN submitted reports" + "submit a new report anonymously OR while
logged in") — a contribution-level flag would risk exposing the private
sibling entries the moment an author forgets to also lock them down
individually. Per-entry keeps the blast radius of one `anonymous: true` to
exactly the one entry that set it (ADR-005).

**Decision: `anonymous` and non-`low` `minTrust` are mutually exclusive on
one entry.** There is no subject to compare a trust level against on an
anonymous call, so the two are contradictory on the same entry. The
normaliser (already the fail-closed sanitiser every entry passes through)
drops `anonymous` when a HIGHER-than-low `minTrust` is also present on the
same entry — the entry falls back to requiring an authenticated,
trust-checked bearer, never the reverse. A malformed manifest must never
accidentally widen access.

**Requirement (spec'd in `specs/portal-page-provisioning/spec.md`):**
`PortalAuthMiddleware::beforeController()` gains an anonymous-allowed branch.
Today it unconditionally throws `PortalUnauthorizedException` when no bearer
resolves for any `PortalProtected` controller method
(`PortalAuthMiddleware.php:78-88`). This change adds: when no bearer
resolves, before throwing, check `PortalContributionRegistry::
aggregateAnonymous()` for a matching entry —
- for `create(register, schema)`: an `anonymous: true`, `type: create` action
  for that exact `(register, schema)`;
- for `index()`: any anonymous entry exists at all (so an anonymous visitor
  gets `aggregateAnonymous()`'s manifest slice instead of a page-shaped 401).

On a match, let the request through (no exception); the controller method
receives `subject === null` and branches on that (new
`authorisedAnonymousCreateAction()` alongside the existing
`authorisedCreateAction()`). On no match, throw exactly as today — the
default for every entry that never opts in is byte-identical to current
behaviour. `type: update` and `type: endpoint` actions are NOT part of the
anonymous surface in this change (an anonymous caller has no existing row to
update and no subject identity to carry in an `X-Portal-Subject` assertion) —
flagged as a Non-Goal below, not a blocker.

**Write path:** `PortalObjectWriter::createAnonymousObject(register, schema,
data)` — the anonymous sibling of `createObject()` — calls the same
`ObjectService::saveObject(..., _rbac: false, _multitenancy: false)` but
stamps NO `scopeField`/`subjectRef`/`organisation` (there is no subject).
`ObjectCreatedEvent` still fires unconditionally on any `saveObject()` call
(OpenRegister's `SaveObjects` service dispatches it regardless of `_rbac`),
so any `x-openregister-flows` declared on the target schema still run —
automatic, no code in this change touches that path.

## ADR conventions applied

- **ADR-022** (apps consume OR abstractions): the built-in provider reads
  through the existing `PortalObjectReader`, never a raw OR client call
  cross-app; `portalPage` is a plain OpenRegister object, not a parallel
  store.
- **ADR-011** (schema property titles/descriptions): every property in the
  schema above carries both.
- **ADR-031** (schema-declarative business logic / notification dialect):
  this change adds no `x-openregister-notifications` block — a provisioned
  page's own audience already gets messages through the existing
  `portalMessage`/inbox surface (unchanged); flagged as a Non-Goal, not
  silently skipped.
- **ADR-037** (modular register fragments): Portaliq has not adopted
  `register.d/` fragment merging — `SettingsService` still loads only the
  single `lib/Settings/portaliq_register.json` monolith (verified: no
  `register.d` directory exists, no fragment-merge code in
  `lib/Settings/`). This change edits the monolith directly, matching every
  prior Portaliq change (contract-v2, field-projection, etc.). Adopting
  ADR-037 fragments for Portaliq is an orthogonal, app-wide decision — out
  of scope here (see Open Question OQ3).
- **ADR-062 relation dialect**: not applicable — see "Why no `$ref`" above.
- **ADR-005** (fail-closed security): every new surface (anonymous
  aggregation, the middleware branch, the mutual-exclusion rule, the
  first-match-not-merge provider rule) defaults to "narrower access, not
  wider" on any ambiguity.

## Non-Goals

- Frontend SPA routing/UX for an anonymous form page (which URL an anonymous
  visitor lands on, how the SPA distinguishes "my portal" from "public form")
  — backend manifest + write-path only in this change.
- `type: update`/`type: endpoint` anonymous actions.
- Anti-abuse/rate-limiting for anonymous writes (CAPTCHA, throttling) — flagged
  as a follow-up, not a blocker for shipping the mechanism.
- An admin UI specifically for authoring `portalPage` objects — the standard
  OR objects UI/API already covers create/edit for any registered schema.

## Open Questions

- **OQ1 — Replace the FQCN's current occupant vs. ship a second class.**
  `OCA\Portaliq\Portal\PortalContributionProvider` already exists as a
  hardcoded demo provider. **Chosen: replace its body**, not add a
  differently-named class, because (a) `PortalContributionRegistry` discovers
  exactly one class per app by convention FQCN
  (`resolveProvider()`, `PortalContributionRegistry.php:234-253`) — a second
  class at a different name would need EITHER a registry change (against
  "SPEC ARTIFACTS ONLY... reuse the existing aggregation path" framing) OR
  would simply never be discovered; (b) the existing class's own docblock
  already says "delete it once real contributions exist" — folding the
  config-driven read into it IS that designed replacement point, not a
  collision; (c) the demo manifest becomes seed `portalPage` objects
  (`x-openregister` seed data, same convention as the existing
  `dev-supplier-account` seed objects) instead of a hardcoded PHP array,
  preserving the dev-exercisability property without a second code path for
  the same app. Rejected alternative: keep the hardcoded demo AND add
  config-driven reads as a second method the registry merges — rejected
  because it duplicates the "one contribution per app" invariant the
  registry currently guarantees, for no benefit the schema-driven model
  doesn't already provide.
- **OQ2 — Multiple active `portalPage` objects for the same audience.** This
  change picks the first (by object id) and logs a warning; it does not
  merge. A future change could support an explicit ordering/priority field
  or multiple simultaneous contributions per audience — deferred, not needed
  for the OpenBuild use case that motivated this change (one page per app
  per audience).
- **OQ3 — Should Portaliq adopt ADR-037 register fragments now?** Not
  addressed here; the monolith edit this change makes is a small, additive,
  single-branch change with no concurrency pressure. Revisit if/when
  Portaliq starts running multiple concurrent `portalPage`-schema-touching
  changes.
- **Elevated-trust pages depend on a live identity-provider integration
  (informational, not a blocker).** `portalAccount.identityType` currently
  only has a working `dev` issuer end-to-end (`eherkenning`/`digid`/`eidas`
  are enum values, not yet wired to a real IdP). A `portalPage` declaring
  `minTrust: substantial|high` on a non-anonymous entry inherits that
  pre-existing limitation — exactly like every other trust-gated entry in
  the fleet today, PHP-provided or not. This does **not** affect
  `anonymous: true` entries, which need no session, no trust level, and no
  identity provider at all — anonymous intake is fully available now and is
  not gated on this.

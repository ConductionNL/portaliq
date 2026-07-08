# Design: contribution-manifest-v3

## Where it slots in

`PortalContributionRegistry::aggregateFor()` today stamps `app` and calls
`filterByTrust()`, then returns the contributions verbatim — the manifest is
opaque past that point. v3 adds ONE normalisation pass, `PortalManifestNormaliser`,
run per contribution AFTER `filterByTrust` (so trust-dropped collections/actions
are already gone and cannot be referenced by a surviving page):

```
aggregateFor()
  └─ per provider: getContribution() → filterByTrust() → normaliseManifest()
                                                          └─ NEW (this change)
```

`normaliseManifest()` is a pure, fail-closed sanitiser. It never throws; every
branch that rejects input returns the safe subset. It is the single point where
the v3 vocabulary is validated, mirroring how `PortalSessionService` is the
single trust-normalisation point.

## Data shapes (the frozen vocabulary)

### Collection (v3 additions, all optional)
```jsonc
{
  // ... v2 fields (register, schema, scopeField, label, listable, kind,
  //     minTrust, scopeClaim, via, fields) unchanged ...
  "columns": [
    { "field": "title",   "label": "Onderwerp" },
    { "field": "status",  "label": "Status", "render": "badge" },
    { "field": "createdAt","label": "Aangemaakt", "render": "date" }
  ],
  "detail":       { "layout": "card", "fields": ["title", "status", "body"] },
  "defaultSort":  { "field": "createdAt", "direction": "desc" },
  "defaultFilters": { "status": "open" }
}
```
- `columns[].render` ∈ `{ text, date, datetime, badge, currency, boolean, link }`
  (default `text`); an unknown render kind normalises to `text`.
- `detail.layout` ∈ `{ card, timeline }` (default `card`).
- `defaultSort.direction` ∈ `{ asc, desc }` (default `asc`).
- A `columns[].field` / `detail.fields[]` entry not in the collection's `fields`
  projection is KEPT in the config but renders blank client-side (projection is
  the authority) — it is NOT stripped here, so a provider that projects nothing
  (full rows) can still name columns freely.

### Action (v3 additions, all optional)
```jsonc
{
  // ... v2 fields (id, type, label, register, schema, scopeField, fields,
  //     minTrust | endpoint, method) unchanged ...
  "fieldConfigs": {
    "title":  { "label": "Onderwerp", "required": true, "size": "large" },
    "body":   { "label": "Bericht", "placeholder": "Beschrijf uw vraag" }
  },
  "optionsProviders": {
    "category": { "type": "static",
                  "options": [ { "value": "billing", "label": "Facturatie" } ] },
    "contract": { "type": "collection",
                  "register": "procest", "schema": "supplierContract",
                  "labelField": "name", "valueField": "id" }
  },
  "submitLabel": "Versturen",
  "successMessage": "Uw bericht is verstuurd"
}
```
- `fieldConfigs` keys are field names; a key NOT in the action's `fields`
  whitelist is dropped (it can only ever describe a whitelisted field).
- `fieldConfigs[].size` ∈ `{ small, medium, large, full }` (default `medium`).
- `optionsProviders[].type` ∈ `{ static, collection }`. `static` needs
  `options: [{value,label}]`; `collection` needs `register, schema, labelField,
  valueField` (all non-empty strings) — the frontend fetches it through the
  scoped collection endpoint. Any other shape drops that provider.

### Contribution `pages` (new, optional)
```jsonc
{
  "app": "pipelinq", "label": "Support",
  "collections": [ ... ], "actions": [ ... ],
  "pages": [
    { "id": "tickets", "label": "Mijn tickets", "icon": "MessageText",
      "blocks": [
        { "type": "richText",   "markdown": "## Openstaande vragen" },
        { "type": "action",     "action": "createTicket" },
        { "type": "collection", "collection": "tickets" }
      ] }
  ]
}
```
- `pages[].blocks[].type` ∈ the block registry:
  - `collection` → `{ collection: <collectionId> }` (the scoped table)
  - `action` → `{ action: <actionId> }` (create/update form)
  - `detail` → `{ collection: <collectionId> }` (single-object detail)
  - `richText` → `{ markdown: <string> }`
  - `cta` → `{ label: <string>, action: <actionId> }`
- A block whose `collection`/`action` id does NOT resolve within the SAME
  (already trust-filtered) contribution is dropped. A page left with zero blocks
  is dropped. An unknown block `type` is dropped.
- **Absent `pages`** → the normaliser synthesises a default page per `listable`
  collection (id = collection id, blocks = `[{action for that schema?}, {collection}]`),
  preserving v2 rendering.

## Fail-closed algorithm (normaliseManifest)

1. `columns`: keep entries that are arrays with a non-empty string `field`;
   normalise `render` to the enum (unknown → `text`); drop the whole `columns`
   key if not a list.
2. `detail`: keep only when an array with `layout` normalised to the enum and
   `fields` a list of strings; else drop the key.
3. `defaultSort` / `defaultFilters`: keep only well-typed; else drop.
4. `fieldConfigs`: keep only keys present in the action's `fields`; per-entry
   normalise `size`, coerce booleans; drop malformed entries.
5. `optionsProviders`: keep only valid `static` / `collection` shapes; drop
   others.
6. `pages`: for each page, filter `blocks` to the registry with resolvable refs;
   drop empty pages; if the whole `pages` key is malformed or empties out,
   synthesise defaults.
7. Nothing here EVER adds a field to a `fields` whitelist or a collection's
   scope — those are read-only inputs to the normaliser.

## Why validate server-side at all (the manifest is opaque)

Two reasons: (a) a contributing app is duck-typed and untrusted-ish — a buggy or
hostile provider must not be able to render a dropdown against an unscoped
register or reference another app's action; (b) the frontend engine should
receive a **canonical, safe** config and never defend against malformed manifests
itself. Validation server-side keeps the trust boundary in one language (PHP,
tested) rather than split across the React engine.

## Testing

- Unit: the normaliser table — each vocabulary key valid/malformed/absent; the
  reference-resolution (block → collection/action within contribution, cross-
  contribution dropped); the fail-closed defaults (absent pages → synthesised);
  the invariant that `fieldConfigs`/`columns` never mutate `fields`/scope.
- The aggregate still returns v2 manifests byte-identical when no v3 keys present
  (additive-compat pin).

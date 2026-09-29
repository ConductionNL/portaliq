# portal-contribution-contract — delta: contribution-manifest-v3

**OpenSpec change**: `contribution-manifest-v3` (ADDED requirements; additive to
contract v2.2). Every requirement here is a presentation-layer extension; none
changes the data-access authorities (whitelist, scope, projection) established by
`supplier-portal`, `contract-v2`, `field-projection`, and `reverse-scope-join`.

## ADDED Requirements

### Requirement: Manifest UI configuration is presentation-only

The manifest MAY carry UI-configuration keys (collection `columns`/`detail`/
`defaultSort`/`defaultFilters`; action `fieldConfigs`/`optionsProviders`/
`submitLabel`/`successMessage`; contribution `pages`). These are consumed by the
portal frontend for rendering ONLY. They MUST NOT influence, widen, or bypass:
the action `fields` whitelist (create/update authority), collection scoping
(`verifyScope`/`via`/`scopeClaim`), or read-side field projection. All keys are
optional; a manifest with none is a valid v2 manifest and renders as before.

#### Scenario: A field config never widens the create whitelist

- GIVEN an action whose `fields` whitelist is `["title"]`
- AND a `fieldConfigs` entry marking `status` as `visible: true, required: true`
- WHEN a subject submits `{ "title": "x", "status": "approved" }`
- THEN only `title` is accepted (the whitelist is unchanged); `status` is dropped
- AND the `fieldConfigs.status` entry is removed from the normalised manifest
  (a field config may only describe a whitelisted field)

#### Scenario: A column naming a projected-away field never leaks it

- GIVEN a collection whose `fields` projection is `["title", "status"]`
- AND a `columns` entry `{ "field": "internalNotes", "render": "text" }`
- WHEN the collection is read and rendered
- THEN rows carry only `title`/`status`/identifiers (projection is the authority)
- AND the `internalNotes` column renders blank — the value is never returned

### Requirement: Scoped option providers

An action field MAY declare an `optionsProvider`. A `static` provider carries an
inline `options: [{value,label}]` list. A `collection` provider carries
`{register, schema, labelField, valueField}` and the portal populates the
dropdown by fetching that collection through the SUBJECT-SCOPED
`/portal/api/collections/{register}/{schema}` endpoint. A `collection` provider
therefore can only ever offer values the subject may already read; it cannot
widen access. A provider of any other shape is dropped fail-closed.

#### Scenario: A collection dropdown is scoped to the subject

- GIVEN an `optionsProvider` of type `collection` for `procest/supplierContract`
- WHEN the portal renders the form for subject `s1`
- THEN the dropdown options are exactly the `supplierContract` rows `s1` may read
  (fetched via the scoped collection endpoint), never another supplier's rows

#### Scenario: A malformed option provider is dropped

- GIVEN an `optionsProvider` missing `valueField` (or with a non-string register)
- WHEN the manifest is normalised
- THEN that provider is removed and the field renders as a plain input
- AND normalisation does not fail

### Requirement: Page composition with resolvable, same-contribution blocks

A contribution MAY declare `pages`, each an ordered list of typed `blocks`
(`collection`, `action`, `detail`, `richText`, `cta`). A `collection`/`detail`
block references a collection by id; an `action`/`cta` block references an action
by id. Every reference MUST resolve within the SAME contribution AFTER trust
filtering; a block whose reference does not resolve, or whose `type` is unknown,
is dropped. A page reduced to zero blocks is dropped. When a contribution
declares no valid `pages`, the portal synthesises one default page per `listable`
collection so v2 rendering is preserved.

#### Scenario: A block referencing a trust-dropped action is removed

- GIVEN a page with an `action` block referencing action `approve`
- AND `approve` has `minTrust: high` and the subject is `low` trust
- WHEN the manifest is normalised for that subject
- THEN `approve` is already gone (trust filter) AND the `action` block is dropped
- AND if that leaves the page empty, the page is dropped

#### Scenario: A cross-contribution reference is refused

- GIVEN app A's page declares a `collection` block referencing app B's collection id
- WHEN A's manifest is normalised
- THEN the block is dropped (references resolve only within the same contribution)

#### Scenario: Absent pages synthesise defaults

- GIVEN a contribution with two `listable` collections and no `pages`
- WHEN the manifest is normalised
- THEN two default pages are synthesised, one per collection, each rendering that
  collection's table (and its create action when one is declared for the schema)

### Requirement: v2 manifests are unchanged by normalisation

Normalisation MUST be a no-op on the data-bearing v2 fields. A manifest carrying
no v3 UI-configuration keys MUST pass through with its collections and actions
byte-identical (aside from the always-added default `pages` synthesis, which is
additive and never mutates existing keys).

#### Scenario: A pure v2 manifest round-trips

- GIVEN a v2 contribution (collections + actions, no v3 keys)
- WHEN it is normalised
- THEN every collection and action is unchanged
- AND a synthesised `pages` array is added (default page per listable collection)

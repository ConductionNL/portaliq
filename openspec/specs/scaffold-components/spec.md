---
example: true
capability: scaffold-components
status: example
built_by: openspec/changes/scaffold-v2
---

# Scaffold Components Specification

> ⚠️ **EXAMPLE SPEC** — This spec lives in the `portaliq` repository
> as a demonstration of the OpenSpec format for the demo components the scaffold
> ships under `src/cellRenderers/` and `src/modals/`.
> Each is the canonical starting point for one of the five registry kinds
> (hydra ADR-036): a cell-renderer and a modal. Apps built
> from this template replace or delete these once they have their own; this spec
> documents the small but real runtime behaviour each demonstrates so the
> `@spec` references in the demo code resolve. The *registry-shape* requirements
> (how these get registered) live in `openspec/specs/scaffold-v2/` — confusingly
> the same change; this capability is specifically the **runtime behaviour**.

## Purpose

The five-kind registry (ADR-036) lets a manifest wire consumer-supplied
components into typed pages. The scaffold ships one minimal, working example
per slot-bearing kind so a freshly-cloned template renders something real:

- `StatusBadge.vue` (`kind: cell-renderer`) — substitutes for a table column,
  rendering the cell value as a coloured badge whose CSS class is derived from
  the value.
- `ExampleModal.vue` (`kind: modal`) — a confirm/cancel dialog opened via a
  manifest `open-modal` action; it relays the user's choice to the parent via
  events and closes itself.

## Requirements

### REQ-COMP-001: Cell renderer derives a CSS-safe class from the value

@e2e exclude `StatusBadge` is registered but can never bind, so no rendered
table cell in this app is one. `src/registry.js` registers it with
`appliesTo: { schema: 'example', property: 'status' }`, and the component's own
docblock says `schema: "item"` — neither string is a schema in this app's
register. `lib/Settings/portaliq_register.json` declares exactly nine schema
slugs (`exampleDocument`, `portalAccount`, `portalAuditEntry`, `portalMessage`,
`portalNotification`, `portalOidcState`, `portalPage`, `portalSession`,
`portalSubmission`), and `tests/e2e/ci-seed.sh` verifies that list on every CI
run. The object table therefore never substitutes this renderer for any column,
and a browser test would assert against a badge that does not render. The
mismatch is filed rather than silently repointed at `exampleDocument`: making a
dead scaffold component live changes what the Documents table looks like, which
is a product decision, not a test fix —
[ConductionNL/portaliq#93](https://github.com/ConductionNL/portaliq/issues/93).

The `StatusBadge` cell renderer MUST display the raw cell value and MUST derive
a CSS-safe modifier class from a normalised form of that value, so that the
object table can colour status cells without the schema dictating CSS. The
normalisation MUST lowercase the value and collapse every run of
non-alphanumeric characters into a single hyphen, falling back to `unknown`
when the value is empty.

#### Scenario: A status value is normalised

- GIVEN a `StatusBadge` rendered with `value="In Progress"`
- WHEN the `normalised` computed runs
- THEN it MUST return `in-progress`
- AND the badge MUST carry the class `status-badge--in-progress`

#### Scenario: Empty value falls back

- GIVEN a `StatusBadge` rendered with an empty `value`
- WHEN `normalised` runs
- THEN it MUST return `unknown`

### REQ-COMP-002: Confirm/cancel modal relays the choice and closes

@e2e exclude `ExampleModal` is opened only by a manifest action of
`type: "open-modal"` targeting the registry key `example-modal`, and
`src/manifest.json` contains no `open-modal` action at all
(`grep -c open-modal src/manifest.json` → 0). There is consequently no control
anywhere in the SPA that opens this dialog, so a browser test would have to
add one — i.e. change the product to create its own subject. Same family as the
`EmailField` exclusion on REQ-COMP-003 below, and the same disposition: the
event contract (`confirm` / `cancel` plus `update:open` deferred to the parent,
per the ADR-004 modal-isolation rule) is a component-level assertion, and
wiring a demo action into the shipped manifest is a product decision —
[ConductionNL/portaliq#93](https://github.com/ConductionNL/portaliq/issues/93).

The `ExampleModal` MUST, on confirm, emit a `confirm` event and request closure
(`update:open` with `false`); on cancel, it MUST emit a `cancel` event and
request closure. The modal MUST NOT close itself directly — it MUST defer the
open-state change to the parent via the `update:open` event (NcDialog
isolation, ADR-004).

#### Scenario: User confirms

- GIVEN an open `ExampleModal`
- WHEN the user activates the Confirm button (`onConfirm`)
- THEN the component MUST emit `confirm`
- AND it MUST emit `update:open` with `false`

#### Scenario: User cancels

- GIVEN an open `ExampleModal`
- WHEN the user activates the Cancel button (`onCancel`)
- THEN the component MUST emit `cancel`
- AND it MUST emit `update:open` with `false`

### REQ-COMP-003: Form-field demo uses the Vue 3 v-model contract and a unique label id

The `EmailField` demo (`kind: form-field`, `appliesTo: { format: "email" }`)
MUST implement the Vue 3 `v-model` contract: it takes the current value as the
`modelValue` prop and reports edits by emitting `update:modelValue`. The Vue 2
`value`/`input` pair is NOT a synonym — bound to a Vue 3 host it binds nothing
and emits into the void, so the field would silently never save.

Each instance MUST also generate its own `id` and point its `<label for=...>`
at it, so that several fields on one form remain individually labelled
(WCAG 2.2 AA 1.3.1 / 4.1.2 — a duplicated `id` collapses the label
association).

@e2e exclude reachable only via a schema fixture this repo's e2e suite does not
provision — `EmailField` IS registered in `src/registry.js` (kind `form-field`,
`appliesTo: { format: "email" }`), so it is not dead code, but it binds only to
an OpenRegister schema property declaring `format: "email"`, and no such
property exists in the bundled manifest or in the seeded e2e fixtures. Reaching
it in a browser would mean authoring a register/schema purely to host it; the
v-model contract and the id uniqueness are component-level assertions.

#### Scenario: User edits the field

- GIVEN an `EmailField` rendered with `modelValue`
- WHEN the user types into the `input`
- THEN the component MUST emit `update:modelValue` with the new input value
- AND the component MUST NOT mutate `modelValue` directly

#### Scenario: Two fields on one form

- GIVEN two `EmailField` instances rendered in the same form
- WHEN the DOM is inspected
- THEN each MUST carry a distinct `fieldId`
- AND each `<label>`'s `for` attribute MUST match its own input's `id`

---
capability: scaffold-components
status: active
---

# Scaffold Components Specification

> The **runtime behaviour** of the registry-wired components portaliq ships
> under `src/cellRenderers/` and `src/modals/` — one cell-renderer and one
> modal, each a working instance of a five-kind registry entry (hydra ADR-036).
> Both directories ship and six live `@spec` references point here.
>
> The registry-*shape* requirements (how components get registered) are owned by
> hydra ADR-036 itself. An earlier version of this note pointed at
> `openspec/specs/scaffold-v2/`, which has never existed in this repository.

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

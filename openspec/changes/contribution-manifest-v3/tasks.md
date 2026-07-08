# Tasks: contribution-manifest-v3

> Additive UI-configuration vocabulary + a fail-closed server-side normaliser.
> Checkbox budget: 5 tasks × 2 = 10 unindented `- [ ]` lines (cap 20).
> Acceptance criteria are plain bullets by design — do not convert them.

## Implementation Tasks

### Task 1: PortalManifestNormaliser — collections + actions
- **spec_ref**: `openspec/changes/contribution-manifest-v3/specs/portal-contribution-contract/spec.md#requirement-manifest-ui-configuration-is-presentation-only`
- **files**: `lib/Contribution/PortalManifestNormaliser.php`, `tests/Unit/Contribution/PortalManifestNormaliserTest.php`
- **acceptance_criteria**:
  - GIVEN a collection WHEN normalised THEN `columns` keeps entries with a non-empty string `field` and a `render` normalised to `{text,date,datetime,badge,currency,boolean,link}` (unknown → `text`); `detail.layout` → `{card,timeline}`; `defaultSort.direction` → `{asc,desc}`; malformed keys are dropped, never fatal
  - GIVEN an action WHEN normalised THEN `fieldConfigs` keeps only keys present in the action's `fields` whitelist with `size` → `{small,medium,large,full}` and boolean coercion; `optionsProviders` keeps only valid `static`/`collection` shapes; malformed drop fail-closed
  - The normaliser NEVER adds a field to `fields`, never alters scope/scopeClaim/via/projection — those are read-only inputs
  - Fail-closed: any non-array/malformed input for a v3 key drops that key and normalisation returns the safe subset

### Task 2: PortalManifestNormaliser — pages + block reference resolution
- **spec_ref**: `openspec/changes/contribution-manifest-v3/specs/portal-contribution-contract/spec.md#requirement-page-composition-with-resolvable-same-contribution-blocks`
- **files**: `lib/Contribution/PortalManifestNormaliser.php`, `tests/Unit/Contribution/PortalManifestNormaliserTest.php`
- **acceptance_criteria**:
  - GIVEN `pages` THEN each block is kept only when its `type` is in `{collection,action,detail,richText,cta}` AND its `collection`/`action` reference resolves within the SAME (already trust-filtered) contribution; unknown types and unresolved/cross-contribution refs are dropped
  - GIVEN a page reduced to zero blocks THEN the page is dropped
  - GIVEN a contribution with no valid `pages` THEN one default page per `listable` collection is synthesised (that collection's table + its create action when declared)
  - `richText` blocks require a string `markdown`; `cta` blocks require a string `label` + a resolvable `action`

### Task 3: Wire the normaliser into the aggregate
- **spec_ref**: `openspec/changes/contribution-manifest-v3/specs/portal-contribution-contract/spec.md#requirement-v2-manifests-are-unchanged-by-normalisation`
- **files**: `lib/Contribution/PortalContributionRegistry.php`, `tests/Unit/Contribution/PortalContributionRegistryTest.php`
- **acceptance_criteria**:
  - `aggregateFor()` runs `normaliseManifest()` per contribution AFTER `filterByTrust()` so trust-dropped entries can never be referenced by a surviving page
  - A pure v2 contribution round-trips with collections + actions byte-identical, plus a synthesised default `pages` array (additive-compat pin)
  - The normaliser is injected/constructed once; failure inside it degrades to the un-normalised-but-trust-filtered manifest, never a 500

### Task 4: Provider interface + demo provider document the vocabulary
- **spec_ref**: `openspec/changes/contribution-manifest-v3/specs/portal-contribution-contract/spec.md#requirement-scoped-option-providers`
- **files**: `lib/Contribution/IPortalContributionProvider.php`, `lib/Portal/PortalContributionProvider.php`
- **acceptance_criteria**:
  - The interface docblock documents the v3 keys as optional/duck-typed with v2-equivalent defaults (no signature change — providers never hard-depend)
  - The demo provider declares ONE `columns`, ONE `fieldConfigs`, ONE `optionsProvider` (static), and ONE `pages` entry so the vocabulary is exercisable on a dev install and by the frontend
  - A `collection` optionsProvider example is documented (commented) pointing at a subject-scoped collection

### Task 5: ADR-063 addendum + README vocabulary reference
- **spec_ref**: `openspec/changes/contribution-manifest-v3/specs/portal-contribution-contract/spec.md#requirement-manifest-ui-configuration-is-presentation-only`
- **files**: `README.md`, `openspec/specs/portal-contribution-contract/spec.md`
- **acceptance_criteria**:
  - README documents the v3 UI-config vocabulary table + the presentation-only invariant + the scoped-dropdown guarantee
  - The main `portal-contribution-contract` spec gains the four ADDED requirements and lists `contribution-manifest-v3` under its OpenSpec changes (kept in sync until archive)
  - The hydra ADR-063 addendum (separate repo) freezes the vocabulary + the four security invariants; cross-referenced here

## Quality checklist

- [ ] `composer check` green (lint, phpcs, psalm, unit)
- [ ] Normaliser is pure + fail-closed (no throws escape; every reject path returns the safe subset)
- [ ] Additive-compat: v2 manifests unchanged aside from additive `pages` synthesis
- [ ] Security invariant covered by a dedicated test (fieldConfig/column never widen access)

# Tasks: portal-status-transitions

> Server-enforced status transitions: action `set` + collection `rowActions` +
> `?action=` disambiguator on the scoped update endpoint.
> Checkbox budget: 4 tasks × 2 = 8 unindented `- [ ]` lines (cap 20).

## Implementation Tasks

### Task 1: Normaliser — action `set` + collection `rowActions`
- **spec_ref**: `openspec/changes/portal-status-transitions/specs/portal-contribution-contract/spec.md#requirement-server-enforced-status-transitions`
- **files**: `lib/Contribution/PortalManifestNormaliser.php`, `tests/Unit/Contribution/PortalManifestNormaliserTest.php`
- **acceptance_criteria**:
  - GIVEN a `type: update` action with `set` THEN only keys in the action's `fields` whitelist with scalar values survive; a smuggled scope field or non-scalar value is dropped; a non-array `set` drops the key
  - GIVEN a collection with `rowActions` THEN each id is kept only when it resolves to a `type: update` action in the SAME contribution; a create/unknown/foreign id is dropped and an emptied list removes the key
  - Fail-closed, never throws; runs after the actions/collections are otherwise normalised

### Task 2: Update endpoint — `?action=` + server-enforced `set`
- **spec_ref**: `openspec/changes/portal-status-transitions/specs/portal-contribution-contract/spec.md#requirement-server-enforced-status-transitions`
- **files**: `lib/Controller/ContributionController.php`
- **acceptance_criteria**:
  - `update()` reads an optional `?action=<id>`; `authorisedUpdateAction` matches that id exactly when given (else first update action — v1 compat)
  - After whitelisting the client body, the matched action's `set` is applied OVER it, honouring only whitelisted fields, BEFORE the writer call — so the transition target is enforced server-side and the client can never choose an arbitrary value
  - Ownership re-verification + scope re-stamp (portal-scoped-crud) still run; a foreign/absent id is still a single 404

### Task 3: Frontend — per-row transition buttons
- **spec_ref**: `openspec/changes/portal-status-transitions/specs/portal-contribution-contract/spec.md#requirement-server-enforced-status-transitions`
- **files**: `src/portal/components/CollectionTable.jsx`, `src/portal/components/PageView.jsx`, `src/portal/App.jsx`, `src/portal/lib/portalApi.js`
- **acceptance_criteria**:
  - A collection with `rowActions` renders an "Acties" column with one button per resolved update action; clicking it PATCHes that row with `?action=<id>` and NO field data, then reloads the collection
  - `portalApi.updateObject` names the action on the wire (`?action=`); the transition target comes from the server's `set`, never the client
  - Live-verified: click "Afhandelen" on an `open` row → status becomes `closed`

### Task 4: Vocabulary docs + demo transition
- **spec_ref**: `openspec/changes/portal-status-transitions/specs/portal-contribution-contract/spec.md#requirement-server-enforced-status-transitions`
- **files**: `lib/Portal/PortalContributionProvider.php`, `README.md`, `openspec/specs/portal-contribution-contract/spec.md`
- **acceptance_criteria**:
  - The demo provider declares a `closeExample` update action (`fields: [status]`, `set: {status: closed}`) + `exampleCollection.rowActions: [closeExample]`
  - README documents `set`, `rowActions`, and the `?action=` disambiguator + the tamper-proof invariant; the main contract spec gains the transition requirement and lists this change

## Quality checklist

- [ ] `composer check` green (lint, phpcs, psalm, phpstan, unit)
- [ ] Security test: `set` for a non-whitelisted field is dropped; a tampered client status is overwritten by `set`
- [ ] Additive-compat: an update action without `set`, and a collection without `rowActions`, behave exactly as before

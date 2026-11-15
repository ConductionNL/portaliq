---
kind: code
---

## Why

The supplier-portal spec's own reference scenario
(`openspec/changes/supplier-portal/specs/supplier-portal/spec.md:51-54`) reads:

> **GIVEN** procest exposes a `PortalContributionProvider` for audience
> `supplier` declaring collections `supplierTender` / `supplierContract` /
> `supplierInvoice`, **actions `requestRenewal` / `submitAccreditation`**, and
> notifications
> **THEN** the supplier sees their tenders, contracts, and invoices, and the
> declared actions

`requestRenewal` and `submitAccreditation` are, by their names and by
`tasks.md:23`'s own note ("REMAINING variant: `endpoint`-style actions that
bearer-forward to a domain app's own endpoint (for non-OR domain actions like
procest request-renewal) — future"), **`endpoint`-type actions**, not
`create`-type OR writes. `IPortalContributionProvider::getContribution()`'s
docblock (`lib/Contribution/IPortalContributionProvider.php:54-58`) explicitly
documents the manifest shape as including `actions` "(each with
id/label/**endpoint**)" — i.e. the contract was designed with endpoint-forwarding
actions in mind from the start.

But only `create`-type actions are implemented end-to-end:

- `ContributionController::create()`
  (`lib/Controller/ContributionController.php:190-218`) and its
  `authorisedCreateAction()` helper only ever look for
  `($action['type'] ?? '') === 'create'` — there is no code path for any other
  action type.
- `PortalContributionProvider::getContribution()` (Portaliq's own demo
  provider, `lib/Portal/PortalContributionProvider.php:102-107`) ships an
  `exampleAction` with `endpoint: null`, and the comment right above it says
  "future".
- `src/portal/App.jsx:215-223` renders any non-`create` action as a
  permanently `disabled` button: `<button ... disabled={!a.endpoint}>` — since
  no action ever has a non-null `endpoint`, this button can never be clicked in
  the current codebase.

Net effect: the spec's own worked example (procest's `requestRenewal` /
`submitAccreditation`) is a documented MUST-scenario that literally cannot be
satisfied by the code as it stands — the moment procest declares those two
actions (its `PortalContributionProvider`, per T09 in the same tasks.md, is
already shipped and could add them today), suppliers would see two permanently
disabled buttons with no way to invoke either action through the portal.

## What Changes

- Extend the contribution action manifest contract
  (`IPortalContributionProvider`) to document `type: "endpoint"` actions:
  `{ id, type: "endpoint", label, appId, path, method, fields }` — `appId` +
  `path` identify the domain app's own bearer-guarded endpoint (e.g. procest's
  `/api/leverancier-portaal/renewals` per `supplier-portal` T10), `method` is
  the HTTP verb, `fields` whitelists client-supplied fields exactly like
  `create` actions already do.
- `ContributionController` gains an `invoke(register-free)` handler (or a new
  route, e.g. `POST /portal/api/actions/{appId}/{actionId}`) that:
  - authorises the same way `create()` already does — the action must appear
    in the subject's own aggregated contributions (`authorisedCreateAction()`'s
    sibling for `endpoint` type);
  - whitelists fields the same way (`whitelist()`, already shared);
  - forwards the request to the domain app's declared endpoint carrying the
    subject's server-derived `subjectRef`/`organisation` (never a client
    value) as a bearer-forwarded or re-signed internal call, so the domain app
    can apply the same fail-closed, subject-scoped logic its own bearer-guarded
    endpoints already implement (per `supplier-portal` DC03 / T10).
- `src/portal/App.jsx` enables the `endpoint`-type action button and posts to
  the new route instead of always rendering `disabled`.
- Portaliq's own demo `PortalContributionProvider` gets a real (harmless, OR
  register-scoped) example `endpoint` action so the pattern is exercisable
  without procest installed, mirroring how `createExample` already
  demonstrates the `create` type.

Not BREAKING: this only adds a previously-declared-but-unimplemented action
type; no existing `create`-type behavior changes.

## Capabilities

### Modified Capabilities
- `supplier-portal`: "An app MUST be able to register a supplier portal
  contribution" gains an implemented `endpoint`-type action path, closing the
  gap between the documented manifest contract and what the read/render path
  actually executes.

## Impact

- `lib/Contribution/IPortalContributionProvider.php` — docblock update
  formalising the `endpoint` action shape.
- `lib/Controller/ContributionController.php` — new authorised-invoke path.
- `appinfo/routes.php` — new route for endpoint-action invocation.
- `lib/Portal/PortalContributionProvider.php` — demo `endpoint` action.
- `src/portal/App.jsx` — enable + wire the endpoint-action button.
- Depends on procest (or another contributing app) declaring a real `endpoint`
  action and exposing the corresponding bearer-guarded endpoint — out of scope
  for this portaliq-only change (tracked as `supplier-portal` T10 in procest's
  own repo).

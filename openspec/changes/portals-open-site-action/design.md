# Design: portals-open-site-action

## Context

Two surfaces exist for one portal object. The admin lists and edits it
(`Portals` / `PortalDetail`, manifest-driven pages over register `portaliq`,
schema `portal`); the public site renders it (`portalPage#site`, the ADR-084
Vue renderer at `/apps/portaliq/site`). Nothing connects the first to the
second.

`PortalResolver::resolve()` decides which portal a site request serves: an
explicit `?portal=<slug>` wins, otherwise the request host is matched against
the verified domains of published portals, and a miss is a deliberate 404
rather than "serve whichever portal happens to own the hostname". So on an
install whose portals carry no verified domain — every development rig — the
slug form is the only entry point, and today an administrator types it by hand.

Constraint that shapes the whole design: the Portals page is not an app-owned
Vue view. It is a manifest declaration rendered by the shared
`CnIndexPage`, so whatever is added has to be expressible in
`src/manifest.json` and resolvable through the registries `main.js` passes
`CnAppRoot`.

## Goals / Non-Goals

**Goals**

- One row action that opens the row's portal site in a new tab.
- Correct on instances with and without URL rewriting.
- Safe for a slug that needs escaping, and honest about a row without one.
- No change to `@conduction/nextcloud-vue`.

**Non-Goals**

- Row-level visibility rules (draft portals keep the action).
- A second affordance on the detail page.
- Domain verification so the bare `/site` resolves (WOO-566).

## Decisions

### Decision 1: `type: "handler"`, not `type: "navigate"`

`manifestActionDispatch.js` resolves an index-page action by its `type`
discriminator. For `navigate` it opens `action.target` verbatim — external
targets via `window.open`, in-app paths via the router — and interpolates no
row fields into it. The `{field}` row-token grammar exists only for the
`params` of a `handler: "navigate"` route push, never for a URL target. A
declaration cannot therefore express "this row's slug" in a query string.

`type: "handler"` with `handler: "openPortalSite"` resolves the string against
the `customComponents` map (`ctx.customComponents[name]`) and calls it with
`{ actionId, item: row }` — the row, which is what carries the slug.

Alternatives considered:

- **`type: "navigate"` with a static target** — would open the bare `/site`,
  which is precisely the not-found page this change exists to avoid.
- **Teach the library to interpolate row tokens into `navigate` targets** — a
  real improvement, and the right fix if a second app needs it, but it changes
  a shared dispatcher for one app's row menu. Kept out; the handler path is the
  documented extension point.
- **A custom Vue page replacing the manifest `Portals` page** — an escape
  hatch (ADR-036 `kind: "page"`) that trades a one-line declaration for a
  hand-maintained index view. Disproportionate.

### Decision 2: The handler is a pure factory with injected collaborators

`src/lib/openPortalSite.js` exports `portalSiteUrl(slug, generateUrl)` and
`createOpenPortalSite({ generateUrl, notify, translate, open })` and imports
NOTHING. `src/customComponents.js` — already the file where the app wires
Vue-land into the registries — supplies `generateUrl` from `@nextcloud/router`,
`showInfo` from `@nextcloud/dialogs` and the app's translator, and registers
the resulting function.

Rationale: the thing worth testing is the URL — the `index.php` prefix, the
encoding, the absent-slug branch — and that is untestable in Node if the module
reaches for Nextcloud's packages at import time. Measured, not assumed: an
earlier cut imported `showInfo` here and `node tests/open-portal-site.spec.mjs`
died on `Unknown file extension ".css"` (the dialogs package pulls its
stylesheet into the module graph) before asserting anything. Same shape as
`src/site/lib/authApi.js`, which `tests/site-auth.spec.mjs` already exercises
as a plain Node script.

### Decision 3: `generateUrl`, and encode the slug

`generateUrl('/apps/portaliq/site')` yields `/index.php/apps/portaliq/site` on
an instance without rewriting and `/apps/portaliq/site` with it — the exact
distinction that made the task-gateway URL (WOO-568) and the mail deeplink
(WOO-570) 404 on this rig when they concatenated a bare path. The slug is
appended as `?portal=` + `encodeURIComponent(slug)`, so a slug containing `&`,
`#`, a space or non-ASCII resolves as itself instead of splitting the query.

`window.open(url, '_blank', 'noopener,noreferrer')` — a new tab, because the
administrator is inspecting the public result of the record they are editing
and should not lose the admin context; `noopener` because the opened document
must not reach back into the admin window.

### Decision 4: Declarative-vs-imperative (ADR-031) — not applicable

The change adds no lifecycle, aggregation, derived field, notification,
relation or dashboard widget. It adds a UI affordance, declared in the manifest
(the declarative surface for this class of behaviour) with a client-side
handler for the one thing JSON cannot express. No `lib/Service/*Service.php` is
introduced; no schema-register patch applies.

## Nextcloud Integration

- Controllers: none changed. The target route `portaliq.portalPage.site`
  already exists and is `#[PublicPage]`.
- Services: none.
- Mappers/Entities: none — Portaliq owns no tables (ADR-046).
- Events/Hooks: none.
- Frontend: `@nextcloud/router` (`generateUrl`), `@nextcloud/dialogs`
  (`showInfo`), the shared `CnIndexPage` action dispatcher, and the app's
  `customComponents` + `icons` registries.

## Security Considerations

- No new endpoint, no new permission surface. The action is a link to an
  already-public route; visibility of the row itself is governed by
  OpenRegister RBAC on the `portal` schema as before.
- The slug is percent-encoded before it enters the query string, so a crafted
  slug cannot inject additional parameters or alter the target path.
- `noopener,noreferrer` on the opened tab prevents the public site document
  from touching `window.opener` in the admin session.
- No secret, token or personal datum is put into a URL.

## NL Design System

Not applicable to the admin surface: the entry is an `NcActionButton` inside
the shared `CnRowActions` menu and inherits Nextcloud's own component styling.
The page it opens is the NL Design System site renderer, unchanged.

## File Structure

```
src/
  lib/
    openPortalSite.js        (new — pure factory, imports nothing)
  customComponents.js        (wire Nextcloud collaborators + register)
  icons.js                   (add OpenInNew)
  manifest.json              (Portals page: config.actions)
l10n/
  en.json, nl.json           (+ generated en.js, nl.js)
package.json                 (check:open-portal-site in check:specs)
tests/
  open-portal-site.spec.mjs  (new — Node spec, wired into check:specs)
  e2e/
    portals-open-site.spec.ts (new — Playwright)
```

## Risks / Trade-offs

- [A draft portal opens the site's not-found page] → The status badge is in the
  same row; the site answers honestly. Revisit when the manifest grammar gains
  a row-level predicate for index actions.
- [Popup blocking] → `window.open` is called synchronously in the click
  handler, keeping it a user-initiated open.
- [The icon string must exist in the app's icon registry] → `CnIcon` falls back
  to a help-circle for an unknown name rather than failing; the e2e spec
  asserts the entry by its label, so a missing icon shows up as a visual
  regression, not a broken action. `OpenInNew` is added in the same change.
- [`slug` is assumed to be the resolver's key] → It is: `resolveByHost` matches
  domains, and the explicit branch compares `site['slug']` to the requested
  portal. The unit spec pins the parameter name.

## Migration Plan

None. Additive frontend change, no stored data and no route changes. Rollback
is a revert of the commit; removing `config.actions` from the `Portals` page
restores the previous menu.

## Open Questions

None blocking. One deferred decision is recorded above (row-level visibility
for draft portals).

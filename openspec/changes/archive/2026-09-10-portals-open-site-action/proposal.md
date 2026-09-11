---
kind: code
---

# Proposal: portals-open-site-action

## Summary

The Portals overview in the Portaliq admin lists every portal but offers no way
to open the thing it describes. This change adds one row action, "Open portal",
to the `Portals` index page: it opens that portal's public site
(`/apps/portaliq/site?portal=<slug>`) in a new tab. One manifest action, one
small handler, no library change.

## Motivation

`/apps/portaliq/site` resolves which portal to serve from the request host,
matched against a portal's VERIFIED domains, and falls back to an explicit
`?portal=<slug>` for a consumer not reaching Portaliq over the site's own
hostname (`PortalResolver::resolve()`). On any install where no portal owns the
hostname — every development rig, and every instance before its DNS is
delegated — the bare `/site` correctly answers "not found", so the only way in
is the slug form, hand-assembled from the address bar.

That was measured on 2026-09-08 during the WOO-556 functional test round:
`/api/content/site` returned `{"error":"not_found"}` for `nextcloud.local`,
while `?portal=demo` and `?portal=testgemeente` both rendered. An administrator
who has just created a portal has no affordance that takes them to it, and the
row menu (View / Edit / Delete) is exactly where they look for one.

Agreed in Slack on 2026-09-08: Wilco Louwerse proposed the action on the
Portals overview; Ruben van der Linde approved it.

## Affected Projects

- [ ] Project: `portaliq` — one `config.actions` entry on the `Portals` manifest
  page, one handler in the app's `customComponents` registry, one icon, two
  translation strings, one unit spec, one e2e spec.

## Scope

### In Scope

- A row action "Open portal" in the three-dot menu of every row on
  `/apps/portaliq/portals`.
- URL built through `generateUrl('/apps/portaliq/site')` so it works with and
  without pretty URLs, with the slug `encodeURIComponent`-escaped.
- A row whose `slug` is empty gets an informational toast, not a broken link.
- Dutch and English strings for the label and the toast (ADR-005 / ADR-007).

### Out of Scope

- Hiding or disabling the action for `draft` portals. A draft resolves to the
  site's own not-found page, which is the honest answer, and the manifest
  grammar has no row-level predicate for index actions today (`visibleWhen` is
  a page/detail-action feature). Deliberately deferred.
- A second entry point on the portal DETAIL page (a header action). Also
  deferred — the overview is where the need was observed.
- Making the bare `/site` work without a slug on a rig. That is domain
  verification, tracked separately as WOO-566.
- Any change to `@conduction/nextcloud-vue`. Row-field interpolation in a
  `type: "navigate"` target would be a generic library improvement; this change
  stays inside the app.

## Approach

The shared `CnIndexPage` already renders a manifest page's `config.actions[]`
into the row overflow menu (`CnRowActions`), and `manifestActionDispatch.js`
resolves `type: "handler"` actions by looking the `handler` string up in the
`customComponents` map that `main.js` hands `CnAppRoot`, calling it with
`{ actionId, item: row }` (REQ-MAD-3). So the action is declared in the
manifest and implemented as one exported function.

`type: "navigate"` was considered first and rejected: the dispatcher opens
`action.target` verbatim and interpolates no row fields, so it cannot carry
`?portal=<this row's slug>`.

## New Dependencies

None.

## Impact

- `src/manifest.json` — `Portals` page gains `config.actions`.
- `src/lib/openPortalSite.js` (new) — URL construction + the open call.
- `src/customComponents.js` — registers the handler under `openPortalSite`.
- `src/icons.js` — adds `OpenInNew` to the icon registry the manifest resolves
  icon strings against.
- `l10n/en.json`, `l10n/nl.json` (+ generated `.js`) — two strings.
- `tests/open-portal-site.spec.mjs` (new, wired into `npm run check:specs`) and
  `tests/e2e/portals-open-site.spec.ts` (new).

No PHP, no routes, no schema, no database. `/apps/portaliq/site` already exists
(`portalPage#site`, `#[PublicPage]`) and is not touched.

## Cross-Project Dependencies

None.

## Risks

### Risk 1: A draft portal's action leads to the site's not-found page

**Severity:** Low — **Mitigation:** The `status` badge sits in the same row, so
the state is visible at the moment of clicking, and the site's own not-found
page is a correct answer rather than an error. Named in Out of Scope so the
follow-up (a row-level `visibleWhen`) is a decision, not an oversight.

### Risk 2: The popup is blocked by the browser

**Severity:** Low — **Mitigation:** `window.open` runs synchronously inside the
click handler, which is what keeps it a user-initiated open in every major
browser. The block is deliberately NOT detected in code: a tab opened with
`noopener` returns null whether it opened or not, so the only reliable
reporter of a refused tab is the browser's own indicator (design.md,
Decision 7).

## Rollback Strategy

Revert the commit. The action is additive: dropping `config.actions` from the
`Portals` page restores the previous menu exactly, and no data or route changes
need undoing.

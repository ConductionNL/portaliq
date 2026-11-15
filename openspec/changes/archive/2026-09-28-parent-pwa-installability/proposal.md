---
kind: code
---

# Proposal: parent-pwa-installability

Learniq round 1 competitor sweep, finding 10.3 "Mobile app (iOS, Android)
or PWA for parents" (`learniq-round1/compare/findings.md` / `change-plan.md`
in ConductionNL/market-intelligence). aula, wilma, edupage, iserv, parentcom
and eleven more all offer a native mobile app; portaliq has zero hits for a
mobile app or a PWA. `tier-b-and-sibling.md` explicitly did not recommend
taking on a native app — a much larger, ongoing commitment (app-store
accounts, review cycles, two more codebases) than this NICE-priority row
needs.

## Summary

A web app manifest and a scoped service worker make the existing portal
SPA installable: "Add to Home Screen" on a phone, a standalone window on
desktop, and a fast reload from cache. No native app, no offline data —
the app SHELL is cached, the API is not.

## Motivation

Every citizen-facing competitor in this cluster ships a native app; the
realistic, buildable answer at NICE priority and without committing to two
app-store codebases is installability of the web app portaliq already
has. A PWA manifest + service worker is the standard, low-commitment way
to close most of the gap this finding describes (an icon on the home
screen, a standalone window, fast reload) without the ongoing cost of
native app maintenance.

## Affected Projects

- [x] Project: `portaliq` — a new controller serving the manifest and
  service worker, one template change, and portal SPA changes (service
  worker registration + an install prompt). No other project's code
  changes.

## Scope

### In Scope

- `PortalManifestController::manifest()` — `#[PublicPage]` GET, returns a
  web app manifest (`application/manifest+json`) resolved through the
  SAME `PortalRuntimeConfigResolver` `PortalPageController::index()`
  already uses, so the manifest names the portal actually being installed
  (`name`/`short_name` from the resolved organisation, `start_url` back
  at the portal with the same `?org=`).
- `PortalManifestController::serviceWorker()` — `#[PublicPage]` GET,
  serves `src/portal/serviceWorker.js`'s contents as
  `application/javascript` with `Service-Worker-Allowed` scoping it to
  the whole portaliq app path.
- Two new routes.
- `templates/portal.php`: one `Util::addHeader()` call linking the
  manifest.
- `src/portal/main.jsx`: registers the service worker after mount,
  fail-silent (a browser without support, or a registration failure,
  never blocks the app booting).
- An install-prompt affordance in the portal shell: captures
  `beforeinstallprompt`, shows a dismissible control, calls `.prompt()`
  on click, hides on `appinstalled` or dismissal.
- The service worker: cache-first-with-network-fallback for the app
  shell's own static assets ONLY (its JS bundle, its CSS, the HTML
  entry). **`/portal/api/*` is never cached** — every bearer-scoped
  request must always reach the network. This is a security requirement,
  not a caching nicety: caching an authenticated response would let a
  later, unauthenticated (or differently-authenticated) load of the same
  URL serve someone else's cached data.

### Out of Scope

- Any native iOS/Android app-store app (per the corpus's own
  recommendation, cited above).
- Per-organisation icon, `theme_color` and `background_color` in the
  manifest. This ships one shared icon (`img/app.svg`, already this
  app's own) and neutral colours; deriving an accurate per-org colour
  needs reading `theme.css`'s `--nldesign-color-*` token resolution,
  which this NICE-priority row does not need answered. Named as a
  follow-up, not silently dropped.
- Offline READS of any portal data. This ships SHELL caching (a fast
  reload, installability) — not an offline-capable app, which would need
  a materially bigger caching/sync design.

## Approach

Reuse the existing branding resolver for the manifest's identity, serve
both new assets through a small dedicated controller (the build's `/js/`
output is gitignored, so a hand-written service worker cannot live
there), and keep the service worker's own logic to exactly one rule: cache
the shell, never the API. Full detail in design.md.

## New Dependencies

None.

## Impact

- `lib/Controller/PortalManifestController.php` (new)
- `src/portal/serviceWorker.js` (new, plain JS, not a webpack entry)
- `appinfo/routes.php` (+2 routes)
- `templates/portal.php` (+1 header link)
- `src/portal/main.jsx` (+ service worker registration)
- `src/portal/App.jsx` (+ install-prompt affordance)
- Unit tests for the controller.

## Cross-Project Dependencies

None.

## Risks

### Risk 1: The service worker caches an authenticated API response
**Severity:** High — **Mitigation:** the service worker's fetch handler
checks the request path FIRST and falls through to a plain, uncached
network fetch for anything under `/portal/api/` (or any path outside its
own shell asset list) before any cache logic runs. A unit test in
`serviceWorker.js`'s own review, and the design.md decision log, both
call this out explicitly as the one rule that must never regress.

### Risk 2: A stale cached shell serves an old bundle after a deploy
**Severity:** Low — **Mitigation:** the service worker's cache name
includes a version string bumped on every deploy-relevant change to
`serviceWorker.js` itself; the `activate` handler deletes any
differently-named cache, so a new deploy's first load clears the old
shell rather than accumulating caches forever.

## Rollback Strategy

Revert the commit. A previously-installed PWA on a user's device would
keep an old service worker until it next re-registers and finds none —
browsers handle an app whose manifest disappears gracefully (it simply
stops offering re-install; an already-installed shortcut still opens the
page over the network). No data migration.

## Open Questions

None — the two scope cuts (no native app, no per-org manifest branding)
are recorded above rather than left implicit.

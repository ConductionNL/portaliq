# Design: parent-pwa-installability

## Architecture Overview

Two new `#[PublicPage]` GET endpoints on a new, small controller; one
`Util::addHeader()` call in the existing template; two small additions to
the portal SPA's boot (`main.jsx`) and shell (`App.jsx`). No new
abstraction, no schema change.

## API Design

### `GET /portal/manifest.webmanifest`
Resolved via the SAME `PortalRuntimeConfigResolver::resolvePortal()` +
`runtimeConfigFor()` calls `PortalPageController::index()` already makes,
from the same `?org=`/`?portal=` query parameters.

**Response** (`Content-Type: application/manifest+json`):
```json
{
  "name": "Gemeente X portaal",
  "short_name": "Portaal",
  "start_url": "/index.php/apps/portaliq/portal?org=gemeente-x",
  "display": "standalone",
  "background_color": "#ffffff",
  "theme_color": "#ffffff",
  "icons": [{"src": "/index.php/apps/portaliq/img/app.svg", "sizes": "any", "type": "image/svg+xml"}]
}
```

### `GET /portal/sw.js`
**Response** (`Content-Type: application/javascript`,
`Service-Worker-Allowed: /index.php/apps/portaliq/`): the literal contents
of `src/portal/serviceWorker.js`.

## Nextcloud Integration

- Controllers: `PortalManifestController` (new)
- Routes: `appinfo/routes.php` (+2, both `#[PublicPage]`)
- Templates: `templates/portal.php` (+1 `Util::addHeader()` call, built
  from the SAME `$orgValue` `PortalPageController::index()` already
  resolves — passed to the template the way `runtimeConfig` already is,
  so the manifest link and the page's own branding can never name two
  different tenants)

## Security Considerations

**D-1: The service worker's ONE rule.** `serviceWorker.js`'s fetch
handler checks the request URL's path against a small allow-list of the
shell's own static assets (the JS bundle, the CSS, the HTML entry) BEFORE
any cache read or write. Anything else — starting with, but not limited
to, `/portal/api/` — falls through to `fetch(event.request)` with no
cache interaction at all. This ordering is the whole security property:
a service worker that cached first and excluded API paths second would
have a window where a bug in the exclusion list silently starts caching
authenticated responses; checking the allow-list first means an unlisted
path is uncached by construction, not by an exclusion rule that has to
stay correct forever.

**D-2: The manifest is public, and that is fine.** The manifest and
service worker routes are `#[PublicPage]` with no bearer/session check —
they carry no data more sensitive than the organisation's own public name
and its own already-public shell assets, the same trust level as the
portal's own login page. Gating them behind auth would break the
"Add to Home Screen" flow, which happens before any session exists.

**D-3: `Service-Worker-Allowed` is scoped to the whole app, not just
`/portal/`.** Without this header a service worker served from
`/index.php/apps/portaliq/portal/sw.js` could by default only control
paths under `/portal/sw.js`'s own directory. Setting it explicitly to
`/index.php/apps/portaliq/` is the documented way to widen a service
worker's control scope beyond its own serving path — needed because the
portal's own routes (`/portal/...`) and its API (also `/portal/api/...`)
already share that prefix, and the header widens control, it does not
narrow the fetch handler's own cache-vs-network decision (D-1 still
applies to everything the wider scope now sees).

## Nextcloud Integration continued: build

`src/portal/serviceWorker.js` is plain, unbundled JavaScript — service
workers are conventionally served as-is (bundling would change its own
URL/hash on every build, which is irrelevant to what it needs to do and
would complicate cache-busting for no benefit). It is NOT added to
`webpack.portal.js`'s `entry`; the controller reads its file contents
directly (`file_get_contents` against a path resolved via
`\OC::$SERVERROOT`/the app's own path helper, the same way any other
static app asset is resolved server-side).

## Trade-offs

- **A controller, not a static file, serves the service worker.** `/js/`
  is entirely gitignored (webpack output), so a hand-written file placed
  there would never be committed and would be at the mercy of the next
  build's `clean: false` output untouched but also un-versioned. Serving
  it from a small controller keeps the script in version control as an
  ordinary reviewable `.js` file.
- **One shared icon and neutral colours, not per-organisation branding.**
  (See proposal.md Out of Scope.) `theme.css`'s CSS custom properties are
  resolved client-side after paint; deriving a manifest's static
  `theme_color` from them server-side would need a second, parallel
  colour-resolution path this change does not need to build to close the
  finding's core gap (an installable app).
- **Shell caching, not offline data.** A cache-first strategy on the
  shell alone gives the two things the finding actually asks for (fast
  reload, "feels like an app") without the correctness burden of caching
  API responses that must reflect the guardian's LIVE record state.

## Open Questions

None.

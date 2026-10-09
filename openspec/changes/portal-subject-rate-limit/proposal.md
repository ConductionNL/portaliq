## Why

Found on the proof instance (portal-proof run 3, De Wilgenboom, 9 October 2026):

- A portal session is not a Nextcloud user, so Nextcloud's limiter counted every portal call as
  anonymous, per IP address. `ContributionController::collection` allowed 60 calls a minute. A
  signed-in page reads 15 to 21 collections, so the fourth page load in a minute got 429 on every
  block, and each block drew an empty list ("Geen items", menu children gone) without a word.
- Pressing Enter in the catalogue's search field searched for `[object Event]`. `WidgetGrid`
  binds `@search` on every widget; a widget that does not declare `search` among its emits gets
  that listener on its root element, where Chrome's own `search` event of an
  `<input type="search">` reached it. The shell then searched for the event.

## What Changes

- `PortalRateLimit`: a call with a portal session counts per subject (300 a minute, one bucket
  per subject whatever the IP); a call without one counts per IP at 60 a minute. `collection()`
  asks it first, and `PortalAuthMiddleware` asks it for a collection read it refuses for want of
  a session (that read never reaches the controller), so it answers 429 instead of 401 over the
  limit. The `#[AnonRateLimit]` on `collection()` goes from 60 to 600 a minute: it stays
  as the outer bound per IP.
- The site page shows "De inhoud kon niet worden geladen." with "Opnieuw proberen" for a table
  or figure block whose read failed, instead of an empty block.
- `WidgetGrid` hands a widget's search to the shell only when it is a string.

## Impact

- Server: new `PortalRateLimit`, `ContributionController::collection` and `PortalAuthMiddleware`
  (optional constructor arguments, autowired). Other portal endpoints keep their limits; the same service can take them
  over one by one.
- Site: `ContributionPage.vue` (load error), `WidgetGrid.vue` (`forwardSearch`).
- Tests: `PortalRateLimitTest`, `PortalAuthMiddlewareTest`, `tests/portal-subject-rate-limit.spec.mjs`.

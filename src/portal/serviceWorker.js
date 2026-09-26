// SPDX-License-Identifier: EUPL-1.2
//
// parent-pwa-installability: caches the portal SPA's own shell (its JS
// bundle and the HTML entry) so a repeat visit, and an installed app's
// launch, are fast and survive a flaky connection. Deliberately narrow:
// this is shell caching for installability, not an offline-capable app —
// no portal DATA is ever cached here.
//
// Plain, unbundled JavaScript on purpose: `/js/` is entirely gitignored
// build output (webpack.portal.js only builds src/portal/main.jsx), so a
// hand-written service worker cannot live there and be reviewable in
// version control. `PortalManifestController::serviceWorker()` serves
// this file's contents as-is; nothing here is a webpack entry.
//
// THE ONE RULE THAT MUST NEVER REGRESS (design.md D-1, proposal.md Risk 1):
// a request whose path contains "/portal/api/" is ALWAYS forwarded to the
// network, with NO cache read and NO cache write, in every code path
// below. That check runs FIRST, before the shell-asset check, so an
// unlisted or unanticipated path is uncached BY CONSTRUCTION — not by an
// exclusion list that has to stay correct forever. Caching an
// authenticated response here would let a later, differently-authenticated
// load of the same URL serve someone else's cached data.

const CACHE_VERSION = 'portaliq-portal-shell-v1'

// Bump CACHE_VERSION on any change to this list, or to the caching logic
// below — the activate handler then deletes the old cache on next launch
// rather than accumulating caches forever (proposal.md Risk 2).
const SHELL_ASSET_SUFFIXES = ['/js/portaliq-portal.js', '/portal']

self.addEventListener('install', () => {
	self.skipWaiting()
})

self.addEventListener('activate', (event) => {
	event.waitUntil(
		caches
			.keys()
			.then((names) =>
				Promise.all(
					names
						.filter((name) => name !== CACHE_VERSION)
						.map((name) => caches.delete(name)),
				),
			),
	)
	self.clients.claim()
})

self.addEventListener('fetch', (event) => {
	const url = new URL(event.request.url)

	// The one rule. See the file header — this check is deliberately first
	// and deliberately a plain substring test, independent of whichever
	// Nextcloud base path (/index.php/apps/... or a rewritten one) is in
	// front of it.
	if (url.pathname.includes('/portal/api/')) {
		return
	}

	if (event.request.method !== 'GET') {
		return
	}

	const isShellAsset = SHELL_ASSET_SUFFIXES.some((suffix) =>
		url.pathname.endsWith(suffix),
	)
	if (!isShellAsset) {
		return
	}

	event.respondWith(cacheFirstThenNetwork(event.request))
})

/**
 * Serve a shell asset from cache when present, refreshing it in the
 * background for next time; otherwise fetch it and cache the result.
 *
 * @param {Request} request The shell asset request.
 * @return {Promise<Response>} The cached or freshly fetched response.
 */
async function cacheFirstThenNetwork(request) {
	const cache = await caches.open(CACHE_VERSION)
	const cached = await cache.match(request)

	if (cached) {
		refreshInBackground(cache, request)
		return cached
	}

	const response = await fetch(request)
	if (response && response.ok) {
		cache.put(request, response.clone())
	}

	return response
}

/**
 * Re-fetch a shell asset and update the cache, without blocking the
 * response that was already served from cache. Failures are swallowed —
 * a flaky network on the background refresh must never surface as an
 * error against the (already-answered) page load.
 *
 * @param {Cache} cache The open shell cache.
 * @param {Request} request The shell asset request.
 * @return {void}
 */
function refreshInBackground(cache, request) {
	fetch(request)
		.then((response) => {
			if (response && response.ok) {
				cache.put(request, response.clone())
			}
		})
		.catch(() => {
			// Best-effort refresh only; the cached copy already answered.
		})
}

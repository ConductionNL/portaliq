// SPDX-License-Identifier: EUPL-1.2
//
// parent-pwa-installability: caches the site's own shell (its JS bundle and
// the HTML entry) so a repeat visit, and an installed app's launch, are fast
// and survive a flaky connection (site-reaches-portal-parity REQ-SRP-045).
// The retired React portal's bundle and address are no longer cached: the
// address now redirects to the site. Deliberately narrow:
// this is shell caching for installability, not an offline-capable app —
// no portal DATA is ever cached here.
//
// Plain, unbundled JavaScript on purpose: `/js/` is entirely gitignored
// build output, so a hand-written service worker cannot live there and be
// reviewable in version control. It lives in src/shared/.
// `PortalManifestController::serviceWorker()` serves
// this file's contents as-is; nothing here is a webpack entry.
//
// THE ONE RULE THAT MUST NEVER REGRESS (design.md D-1, proposal.md Risk 1):
// a request whose path contains "/api/" is ALWAYS forwarded to the
// network, with NO cache read and NO cache write, in every code path
// below. That check runs FIRST, before the shell-asset check, so an
// unlisted or unanticipated path is uncached BY CONSTRUCTION — not by an
// exclusion list that has to stay correct forever. Caching an
// authenticated response here would let a later, differently-authenticated
// load of the same URL serve someone else's cached data.
//
// "/api/", not "/portal/api/", since the worker also serves the site: the
// site's content API lives at "/api/content/…", and its "/api/content/site"
// ends in "/site" exactly like the site's own page. The narrower test would
// have cached that API answer as if it were the shell.

// A BROKEN WORKER MUST NEVER TAKE THE SITE DOWN (fix/service-worker-may-fetch).
// On a custom_apps install the worker was served with Nextcloud's empty
// policy (connect-src 'none'), so its every fetch() rejected. It answered the
// next navigation to /site from an empty cache, its fetch threw, respondWith
// rejected and the browser showed net::ERR_FAILED to every returning visitor.
// The server now allows same-origin fetches, and this file no longer depends
// on that: the worker calls respondWith ONLY for a request it knows it holds
// in its cache (`cachedUrls`, read from the cache when the worker starts and
// kept up to date on every write). Anything else is left to the browser, and
// the cache is filled in the background, where a failed fetch is swallowed.
// A worker whose fetches all fail therefore never caches anything and never
// answers anything.

const CACHE_VERSION = 'portaliq-shell-v4'

// Bump CACHE_VERSION on any change to this list, or to the caching logic
// below — the activate handler then deletes the old cache on next launch
// rather than accumulating caches forever (proposal.md Risk 2).
//
// The site's lazy page chunks are NOT listed: they are only loaded on the
// route that needs them, and their file names change with every build.
const SHELL_ASSET_SUFFIXES = ['/js/portaliq-site.js', '/site']

// The URLs this worker holds in its cache. Empty until the start-up read
// below finishes; until then every request goes to the network, which is
// always safe.
const cachedUrls = new Set()

caches
	.open(CACHE_VERSION)
	.then((cache) => cache.keys())
	.then((requests) => {
		for (const request of requests) {
			cachedUrls.add(request.url)
		}
	})
	.catch(() => {
		// No cache, nothing to answer from: the network serves everything.
	})

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
	if (url.pathname.includes('/api/')) {
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

	if (cachedUrls.has(event.request.url)) {
		event.respondWith(fromCache(event.request))
		return
	}

	// Not cached: the browser fetches it itself, and the worker stores a copy
	// for next time without ever standing in the way of this load.
	event.waitUntil(storeInBackground(event.request))
})

/**
 * Serve a shell asset this worker holds, refreshing it in the background for
 * next time. If the copy is gone after all (the browser may evict a cache),
 * forget it and fetch; the next load then goes straight to the network.
 *
 * @param {Request} request The shell asset request.
 * @return {Promise<Response>} The cached or freshly fetched response.
 */
async function fromCache(request) {
	let cached
	try {
		const cache = await caches.open(CACHE_VERSION)
		cached = await cache.match(request)
	} catch {
		cached = undefined
	}

	if (cached) {
		storeInBackground(request)
		return cached
	}

	cachedUrls.delete(request.url)
	return fetch(request)
}

/**
 * Fetch a shell asset and keep a good answer in the cache. Never rejects: a
 * failed fetch, a blocked policy or a full disk only means nothing is cached,
 * and the page that asked was already served by the browser or the cache.
 *
 * @param {Request} request The shell asset request.
 * @return {Promise<void>}
 */
async function storeInBackground(request) {
	try {
		const response = await fetch(request)
		if (response && response.ok) {
			const cache = await caches.open(CACHE_VERSION)
			await cache.put(request, response.clone())
			cachedUrls.add(request.url)
		}
	} catch {
		// Best effort only. The worker stays out of the way.
	}
}

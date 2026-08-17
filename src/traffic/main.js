/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * The standalone traffic script, for a portal this app does not render.
 *
 * A portal built by `docusaurus-plugin-portaliq` is static HTML on somebody
 * else's host, with portaliq nowhere in the request path. It loads THIS file
 * from its portal, so the code deciding what a visitor's browser may store is
 * the same code the built-in renderer runs — not a copy that can drift, and
 * not a second implementation whose divergence would show up as a portal
 * measuring something it never enabled.
 *
 * It configures itself from its own `<script>` tag and the portal's public
 * content API. Nothing here is Docusaurus-specific; any static site can load
 * it the same way.
 *
 * @spec openspec/changes/portal-traffic-analytics/tasks.md
 */

import { createTrafficClient } from '../shared/trafficClient.js'

/**
 * The `<script>` element that loaded this bundle.
 *
 * `document.currentScript` is null inside a module or an async callback, so it
 * is read at TOP LEVEL, synchronously, before anything else runs.
 *
 * @return {HTMLElement|null} The element.
 */
function ownScript() {
	if (document.currentScript) {
		return document.currentScript
	}

	// A bundle loaded with `defer` or re-executed loses `currentScript`. Fall
	// back to finding it by name rather than giving up: a portal whose
	// measurement silently does not start is indistinguishable from one nobody
	// visits, which is the exact confusion this whole change exists to end.
	return document.querySelector('script[src*="portaliq-traffic"]')
}

const script = ownScript()

/**
 * Read one configuration value, from the script tag or a global.
 *
 * @param {string} name The data attribute name, without the `data-` prefix.
 * @return {string} The value, or ''.
 */
function setting(name) {
	const fromTag = script && script.dataset ? script.dataset[name] : ''
	if (fromTag) {
		return String(fromTag)
	}

	const globals = window.PORTALIQ_TRAFFIC || {}
	return String(globals[name] || '')
}

/**
 * Start measuring, once the portal has said what it measures.
 *
 * THE CONFIGURATION IS FETCHED, NEVER ASSUMED. A static build could have
 * baked the portal's settings in at build time, and that is precisely the
 * shape that keeps measuring after an operator switches measurement off —
 * the site would have to be rebuilt for a privacy decision to take effect.
 *
 * @return {Promise<void>} Resolves once the first page view is queued.
 */
async function start() {
	const origin = setting('origin').replace(/\/$/, '')
	const portal = setting('portal')
	const appPath = setting('appPath') || '/index.php/apps/portaliq'
	const base = origin + appPath

	let config = {}
	try {
		const url = new URL(base + '/api/content/site', origin || window.location.origin)
		if (portal) {
			url.searchParams.set('portal', portal)
		}

		const response = await fetch(url.toString(), { headers: { Accept: 'application/json' } })
		if (response.ok === false) {
			return
		}

		config = (await response.json()).traffic || {}
	} catch {
		// A portal whose API is unreachable is a portal whose collector is
		// unreachable too. Staying silent is the correct outcome, and it must
		// not put an error in a visitor's console on a government site.
		return
	}

	const client = createTrafficClient({
		config,
		endpoint: base + '/api/traffic',
		portal,
		storage: safeStorage(),
		navigator: window.navigator,
		window,
		now: () => Date.now(),
		fetchImpl: window.fetch.bind(window),
		setTimeoutImpl: window.setTimeout.bind(window),
	})

	// Exposed so a portal's own consent banner can grant or withdraw, and so a
	// page can report an event of its own. The name is deliberately boring and
	// namespaced; a static site has one global namespace shared with whatever
	// else its theme loads.
	window.portaliqTraffic = client

	client.pageView()

	// A STATIC SITE NAVIGATES BY LOADING A NEW DOCUMENT, so each page runs this
	// file again and reports itself. The listeners below only cover the events
	// still queued when the document goes away.
	window.addEventListener('pagehide', () => client.flush())
	document.addEventListener('visibilitychange', () => {
		if (document.visibilityState === 'hidden') {
			client.flush()
		}
	})
}

/**
 * `localStorage`, or null when the browser refuses to hand it over.
 *
 * @return {object|null} The storage.
 */
function safeStorage() {
	try {
		return window.localStorage
	} catch {
		return null
	}
}

start()

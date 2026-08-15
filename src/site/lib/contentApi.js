/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The site renderer's ONLY way to reach Portaliq.
 *
 * Every call goes to the public headless content API — the same endpoints a
 * Docusaurus build or any third-party front-end reads (ADR-086 §1). There is
 * deliberately no privileged path: if this renderer ever needed one, that
 * would be a gap in the API, not a shortcut to take here.
 *
 * No `requesttoken`, no `generateUrl`, no `OC*` global. The base URL comes
 * from runtime configuration, which is what lets the same bundle run inside
 * Nextcloud and at a public origin (ADR-084 `host: 'public'`).
 */

import { loadState } from '@nextcloud/initial-state'

/**
 * The runtime configuration for this deployment.
 *
 * TWO sources, in this order, and the order is the whole point:
 *
 *   1. Nextcloud's initial-state channel, when running inside Nextcloud. This
 *      is the blessed, CSP-safe path (`loadState`), not a DOM data-attribute
 *      read.
 *   2. A plain `window` global, when running at a PUBLIC origin — where there
 *      is no initial-state channel, no `OC*`, and no Nextcloud at all.
 *
 * Everything else in this bundle is identical between the two hosts. That is
 * what `host: 'public'` means in practice: a boot-and-transport difference,
 * not a rendering one.
 *
 * @return {object} The runtime config, possibly empty.
 */
export function runtimeConfig() {
	try {
		// `loadState` throws when the initial-state element is absent — which
		// is exactly the public-origin case, where Nextcloud never rendered
		// one. The throw is the signal, not an error.
		return loadState('portaliq', 'siteConfig', {}) || {}
	} catch {
		return window.PORTALIQ_SITE_CONFIG || {}
	}
}

/**
 * Resolve the API base without reaching for a Nextcloud global.
 *
 * @return {string} The content API base, no trailing slash.
 */
export function resolveApiBase() {
	const configured = runtimeConfig().apiBase
	if (typeof configured === 'string' && configured !== '') {
		// The configured value points at the `/site` endpoint; the base is
		// everything before it.
		return configured.replace(/\/site\/?$/, '').replace(/\/$/, '')
	}

	// Fall back to a path relative to the document root. Deliberately NOT the
	// Nextcloud `index.php` prefix: at a public origin that path does not
	// exist, and hard-coding it here would make this bundle Nextcloud-only
	// again by the back door.
	return '/api/content'
}

/**
 * Fetch JSON from the content API.
 *
 * A non-2xx is an ERROR, not an empty result. Returning `{}` on a 404 would
 * make "this site does not exist" render identically to "this site has no
 * content" — the caller could not tell a broken deployment from an empty one.
 *
 * @param {string} path  Endpoint path, e.g. '/menus'.
 * @param {object} query Query parameters.
 * @return {Promise<object>} The parsed body.
 */
async function get(path, query = {}) {
	const url = new URL(resolveApiBase() + path, window.location.origin)
	for (const [key, value] of Object.entries(query)) {
		if (value !== undefined && value !== null && value !== '') {
			url.searchParams.set(key, value)
		}
	}

	const response = await fetch(url.toString(), {
		headers: { Accept: 'application/json' },
	})

	if (!response.ok) {
		const error = new Error(`content api ${response.status} for ${path}`)
		error.status = response.status
		throw error
	}

	return response.json()
}

/**
 * @param {string} [site] Explicit site slug.
 * @return {Promise<object>} The resolved site's presentation record.
 */
export const fetchSite = (site) => get('/site', { site })

/**
 * @param {string} [site] Explicit site slug.
 * @return {Promise<Array>} The site's menus.
 */
export const fetchMenus = (site) => get('/menus', { site }).then((d) => d.menus || [])

/**
 * @param {string} [site] Explicit site slug.
 * @return {Promise<Array>} The site's published page summaries.
 */
export const fetchPages = (site) => get('/pages', { site }).then((d) => d.pages || [])

/**
 * @param {string} [site] Explicit site slug.
 * @return {Promise<Array>} The site's glossary terms.
 */
export const fetchGlossary = (site) => get('/glossary', { site }).then((d) => d.terms || [])

/**
 * @param {string} route  The in-site route.
 * @param {string} [site]  Explicit site slug.
 * @return {Promise<object>} One published page by route.
 */
export const fetchPage = (route, site) => get('/page', { route, site })

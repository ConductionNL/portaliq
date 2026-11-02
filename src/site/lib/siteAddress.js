/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * HOW A SITE PAGE READS IN THE ADDRESS BAR.
 *
 * A page has two addresses. The long one works on every instance:
 * `/apps/portaliq/site?route=/afval`, or with the page in the path,
 * `/apps/portaliq/site/afval`. The short one, `/afval` on the portal's own
 * host, works where a proxy in front of Nextcloud rewrites it to the long
 * one. The server says whether this host has such a rule (`addressing.root`,
 * from the verified domain the request host matches), and which page the bare
 * address of the host opens (`addressing.landingRoute`).
 *
 * The browser decides one thing itself: a page opened on the long address
 * stays on long addresses, whatever the host declares. The long address is
 * never rewritten, so writing a short link from it would be a guess about a
 * proxy this page did not come through.
 *
 * Pure functions that import nothing, so the shell and a node test derive the
 * same address from the same input.
 *
 * @spec openspec/changes/site-at-the-root-of-a-portal-host/specs/portal-settings/spec.md#requirement-the-renderer-must-read-and-write-the-addresses-of-its-host-req-srh-004
 */

/** The long address of the site, with whatever follows it in the path. */
const LONG_ADDRESS = /^(.*\/apps\/portaliq\/site)(\/.*)?$/

/**
 * The addressing in effect for a browser location.
 *
 * @param {object} config The runtime config; `addressing` comes from the server.
 * @param {string} pathname The path in the address bar.
 * @return {{root: boolean, longBase: string, longRest: string, landingRoute: string}}
 *   `root` when links are short addresses, `longBase` the site route when the
 *   browser sits on the long address (else ''), `longRest` the path after it.
 * @spec openspec/changes/site-at-the-root-of-a-portal-host/specs/portal-settings/spec.md#requirement-the-renderer-must-read-and-write-the-addresses-of-its-host-req-srh-004
 */
export function addressingOf(config, pathname) {
	const declared = (config && config.addressing) || {}
	const long = LONG_ADDRESS.exec(String(pathname || ''))
	return {
		root: declared.root === true && !long,
		longBase: long ? long[1] : '',
		longRest: long ? long[2] || '' : '',
		landingRoute:
			typeof declared.landingRoute === 'string' ? declared.landingRoute : '',
	}
}

/**
 * A path from the address bar as a site route: decoded, one leading slash, no
 * trailing slash.
 *
 * @param {string} path The path, or the part of it after the site route.
 * @return {string} The route; '/' for an empty path.
 */
function routeOfPath(path) {
	const parts = String(path || '')
		.split('/')
		.filter((part) => part !== '')
		.map((part) => {
			try {
				return decodeURIComponent(part)
			} catch {
				// A stray `%` is not an encoding. The page is then not found,
				// which is the truth.
				return part
			}
		})
	return `/${parts.join('/')}`
}

/**
 * A site route as a path for the address bar.
 *
 * @param {string} route The route.
 * @return {string} The path, each segment encoded.
 */
function pathOfRoute(route) {
	const parts = String(route || '')
		.split('/')
		.filter((part) => part !== '')
		.map((part) => encodeURIComponent(part))
	return `/${parts.join('/')}`
}

/**
 * The site route a browser location names.
 *
 * In order: `?route=`, the path (the whole path on a short address, the part
 * after the site route on a long one), the landing page of the host, home.
 *
 * @param {{pathname: string, search: string}} location The browser location.
 * @param {object} config The runtime config.
 * @return {string} The route, always with a leading slash.
 * @spec openspec/changes/site-at-the-root-of-a-portal-host/specs/portal-settings/spec.md#requirement-the-renderer-must-read-and-write-the-addresses-of-its-host-req-srh-004
 */
export function routeOfLocation(location, config) {
	const explicit = new URLSearchParams(location.search || '').get('route')
	if (explicit) {
		return explicit.startsWith('/') ? explicit : `/${explicit}`
	}

	const addressing = addressingOf(config, location.pathname)
	const fromPath = routeOfPath(
		addressing.root ? location.pathname : addressing.longRest,
	)
	if (fromPath !== '/') {
		return fromPath
	}

	return addressing.landingRoute || '/'
}

/**
 * Put a route on a URL, in the addressing of the page it is written from.
 *
 * @param {URL} url The URL to change, already holding the parameters to keep.
 * @param {string} route The route.
 * @param {object} config The runtime config.
 * @param {boolean} always Whether home is written as `?route=/` on a long address.
 * @return {URL} The same URL.
 */
function withRoute(url, route, config, always) {
	const addressing = addressingOf(config, url.pathname)
	url.searchParams.delete('route')
	if (addressing.root) {
		url.pathname = pathOfRoute(route)
		return url
	}

	// On `/site/afval` the path names a page too. A link from there starts
	// at the site route again, or every link would open the page it is on.
	if (addressing.longBase) {
		url.pathname = addressing.longBase
	}
	if (always || (route && route !== '/')) {
		url.searchParams.set('route', route)
	}

	return url
}

/**
 * A shareable address for a site route: the portal the page is served as
 * (`?portal=`) and the chosen language (`?lang=`) stay, nothing else does.
 *
 * @param {string} route The route.
 * @param {string} href The address in the address bar.
 * @param {object} config The runtime config.
 * @return {URL} The address.
 * @spec openspec/changes/site-at-the-root-of-a-portal-host/specs/portal-settings/spec.md#requirement-the-renderer-must-read-and-write-the-addresses-of-its-host-req-srh-004
 */
export function addressOfRoute(route, href, config) {
	const url = new URL(href)
	// The portal the page is served as stays on the address: on an instance
	// with several portals, an address without it opens another portal, or
	// none, after a reload or in a new tab.
	const portal = url.searchParams.get('portal')
	// The chosen language stays as well (`?lang=`), so the next page opens in
	// the language the visitor picked.
	const lang = url.searchParams.get('lang')
	url.search = ''
	url.hash = ''
	if (portal) {
		url.searchParams.set('portal', portal)
	}
	if (lang) {
		url.searchParams.set('lang', lang)
	}

	return withRoute(url, route, config, false)
}

/**
 * A shareable address for one record shown on the page it is listed on
 * (`?record=<id>`): the page's route, the portal and the language stay, so
 * the link opens the same portal on a host that serves several.
 *
 * @param {string} id The record id.
 * @param {string} href The address in the address bar.
 * @param {object} config The runtime config.
 * @return {URL} The address.
 * @spec openspec/specs/site-navigation/spec.md#requirement-a-link-to-a-record-or-a-search-result-keeps-the-portal-and-the-route-req-rsl-001
 */
export function addressOfRecord(id, href, config) {
	const url = addressOfRoute(routeOfLocation(new URL(href), config), href, config)
	url.searchParams.set('record', id)

	return url
}

/**
 * The address the address bar takes when the shell moves to a route in place:
 * everything on the current address stays, only the route changes.
 *
 * @param {string} route The route.
 * @param {string} href The address in the address bar.
 * @param {object} config The runtime config.
 * @return {URL} The address.
 * @spec openspec/changes/site-at-the-root-of-a-portal-host/specs/portal-settings/spec.md#requirement-the-renderer-must-read-and-write-the-addresses-of-its-host-req-srh-004
 */
export function addressAfterMove(route, href, config) {
	return withRoute(new URL(href), route, config, true)
}

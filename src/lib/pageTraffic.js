// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// A portal page's traffic (portal-page-traffic): which route the page is
// counted under, and which part of the page endpoint's answer an incoming
// or outgoing list shows. Pure, so a node test can check it without a
// browser.

/**
 * The route a page's traffic is counted under: its `route` with a
 * leading slash and without a trailing one, `/` for the home page. The
 * server normalises the same way (TrafficPagePath::route); this only
 * spares the endpoint a refusal for a route stored without its slash.
 *
 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-a-pages-traffic-must-be-counted-by-its-in-site-route
 * @param {object|null} page The page object.
 * @return {string} The route, or '' when the page has none.
 */
export function pageRoute(page) {
	const raw = page && page.route
	if (raw === undefined || raw === null) {
		return ''
	}
	let route = String(raw).trim()
	if (route.charAt(0) !== '/') {
		route = '/' + route
	}
	if (route.length > 1) {
		route = route.replace(/\/+$/, '')
	}
	return route === '' ? '/' : route
}

/**
 * The three lists one direction shows, from the page endpoint's answer.
 *
 * Incoming: the pages visitors came from, the sessions that entered the
 * portal here, and the sites and channels that brought them. Outgoing:
 * the pages they went to next, the sessions that left the portal here,
 * and the outbound links clicked here. A list the answer carries as null
 * (no day of the period counted it) stays null, so the widget can say
 * "Not available for this period" instead of drawing an empty table.
 *
 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-the-page-detail-must-show-where-visitors-came-from-and-went-next
 * @param {object|null} answer    The endpoint's answer.
 * @param {string}      direction `incoming` or `outgoing`.
 * @return {{pages: Array<object>|null, boundary: number|null, sources: Array<object>|null}} The lists.
 */
export function flowOf(answer, direction) {
	const data = answer || {}
	if (direction === 'outgoing') {
		return {
			pages: listOrNull(data.next),
			boundary: numberOrNull(data.exits),
			sources: listOrNull(data.outbound),
		}
	}
	return {
		pages: listOrNull(data.previous),
		boundary: numberOrNull(data.entrances),
		sources: listOrNull(data.referrers),
	}
}

/**
 * An array, or null for anything else.
 *
 * @param {unknown} value The value.
 * @return {Array<object>|null} The array.
 */
function listOrNull(value) {
	return Array.isArray(value) ? value : null
}

/**
 * A finite number, or null for anything else.
 *
 * @param {unknown} value The value.
 * @return {number|null} The number.
 */
function numberOrNull(value) {
	return typeof value === 'number' && Number.isFinite(value) ? value : null
}

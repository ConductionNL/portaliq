/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Which menu item marks the page on screen (site-page-layout).
 *
 * The item whose link IS the route is the current page (`aria-current="page"`).
 * An item whose link is a section the route sits in ("/praktisch" for
 * "/praktisch/afwezig-melden") is the current location
 * (`aria-current="true"`): the menu shows where the visitor is, and a screen
 * reader says so, without claiming the item opens this very page.
 */

/**
 * A route without its query, hash and trailing slash.
 *
 * @param {string} route The route.
 * @return {string} The path.
 */
function pathOf(route) {
	const path = String(route || '').split(/[?#]/)[0]
	return path.length > 1 ? path.replace(/\/+$/, '') : path
}

/**
 * The `aria-current` value for a menu link on a route.
 *
 * @param {string} link  The item's link.
 * @param {string} route The route on screen.
 * @return {'page'|'true'|undefined} `page` for the page itself, `true` for its
 *                                   section, else nothing.
 * @spec openspec/changes/site-page-layout/specs/site-look/spec.md#requirement-the-menu-must-mark-the-section-of-the-page-on-screen
 */
export function menuCurrent(link, route) {
	if (typeof link !== 'string' || link.startsWith('/') === false) {
		return undefined
	}

	const item = pathOf(link)
	const here = pathOf(route)
	if (item === here) {
		return 'page'
	}

	if (item !== '/' && here.startsWith(`${item}/`)) {
		return 'true'
	}

	return undefined
}

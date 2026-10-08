// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// An authored link, made safe and made to work wherever the site is served
// (site-school-blocks). A path inside the site (`/praktisch`) becomes the
// site's own address for that route, so it works on a portal at its own
// address and on one served through Nextcloud (`?route=/praktisch`); a click
// on it stays in the site. `http(s):`, `mailto:` and `tel:` pass as they are.
// Anything else is no link at all.
//
// @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-task-list-shows-a-portals-most-asked-tasks-as-tiles

import { siteHref } from './rows.js'

/**
 * @param {string} value The authored address.
 * @return {{href: string, route: string}|null} The address and, for a path in the site, its route; null when it may not be a link.
 */
export function authoredLink(value) {
	const raw = String(value ?? '').trim()
	if (/^\/(?!\/)/.test(raw)) {
		return { href: siteHref(raw), route: raw }
	}
	if (/^(https?:\/\/|mailto:|tel:)/i.test(raw)) {
		return { href: raw, route: '' }
	}
	return null
}

/**
 * Whether a click should stay in the site: a plain left click on a link to a
 * route. A click for a new tab or window is the browser's.
 *
 * @param {MouseEvent} event The click.
 * @param {{route: string}|null} link The link.
 * @return {boolean} True when the caller should navigate in place.
 */
export function staysInSite(event, link) {
	return Boolean(
		link
		&& link.route
		&& !(event?.ctrlKey || event?.metaKey || event?.shiftKey || event?.altKey)
		&& (event?.button ?? 0) === 0,
	)
}

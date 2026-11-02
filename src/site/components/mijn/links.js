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
		// A query (`/zoeken?f.wooCategory=c1`) rides beside the route, so the
		// page it opens reads it from the address.
		const at = raw.indexOf('?')
		return at === -1
			? { href: siteHref(raw), route: raw }
			: {
					href: withQuery(siteHref(raw.slice(0, at)), raw.slice(at + 1)),
					route: raw,
				}
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

/**
 * An address with a link's own query added to it.
 *
 * @param {string} href The address of the route.
 * @param {string} query The query, without `?`.
 * @return {string} The address.
 * @spec openspec/changes/hero-links-follow-the-site-address/specs/site-navigation/spec.md#requirement-a-block-link-to-a-site-page-carries-the-sites-own-address
 */
function withQuery(href, query) {
	if (!query) {
		return href
	}
	try {
		const url = new URL(href)
		for (const [key, value] of new URLSearchParams(query)) {
			url.searchParams.append(key, value)
		}
		return url.toString()
	} catch {
		return `${href}?${query}`
	}
}

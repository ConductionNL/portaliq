/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * "View on the site" — where a portal page is served publicly.
 *
 * WHY THE SLUG IS CARRIED. `PortalResolver` serves a site request by matching
 * the request HOST against the verified domains of published portals, and only
 * falls back to an explicit `?portal=` slug for a caller that does not reach
 * the site over its own hostname. The admin UI is exactly such a caller: on
 * every development rig, and on every instance before a domain is delegated
 * and verified, the host match cannot succeed — so the designer's bare
 * `?route=/` link landed on the site's not-found page instead of the page
 * being edited (WOO-565, finding B21).
 *
 * WHY NO LOOKUP. A page object stores its portal BY SLUG (`page.portal`, e.g.
 * `open-tilburg`), and the slug is exactly what the resolver compares against
 * `site.slug`. Nothing has to be fetched to address the page.
 *
 * WHY THIS MODULE IMPORTS NOTHING. `generateUrl` is handed in, so
 * `node --test tests/page-layout-entry.spec.mjs` can exercise the builder as a
 * plain module. Same shape as `src/site/lib/authApi.js`.
 *
 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-site-must-offer-an-editing-entry-point-only-to-a-visitor-who-may-edit
 */

/** The app route that renders a portal's public site (`portalPage#site`). */
const SITE_PATH = '/apps/portaliq/site'

/**
 * Build the public-site URL for one portal page.
 *
 * @param {object} page The page object (`route`, `portal`).
 * @param {(path: string) => string} generateUrlFn Nextcloud's URL generator.
 * @return {string} The site URL for this page, carrying its portal when known.
 *
 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-site-must-offer-an-editing-entry-point-only-to-a-visitor-who-may-edit
 */
export function pageSiteUrl(page, generateUrlFn) {
	const route = String(page?.route || '/')
	// encodeURIComponent, not a template literal: a slug or route carrying
	// `&`, `#`, a space or non-ASCII would otherwise split the query or
	// resolve to a different portal than the page belongs to.
	const base = `${generateUrlFn(SITE_PATH)}?route=${encodeURIComponent(route)}`
	const portal = String(page?.portal || '').trim()

	// A page with no portal cannot be addressed by slug at all, and the bare
	// route is still better than nothing: on an instance whose domain IS
	// delegated, the host match resolves it.
	return portal === '' ? base : `${base}&portal=${encodeURIComponent(portal)}`
}

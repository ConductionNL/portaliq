/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * "View on the site" — where a portal page is served publicly.
 *
 * WHY THE SLUG IS CARRIED. `PortalResolver::resolve()` consults an EXPLICIT
 * `?portal=` slug FIRST and only host-matches when none is named — and a slug
 * that matches no published portal resolves to nothing rather than falling
 * back to the host ("a named site that does not exist is a miss, NOT an
 * invitation to fall through"). Naming the page's own portal is therefore the
 * only way to address it from a caller that does not arrive over the portal's
 * own hostname: on every development rig, and on every instance before a
 * domain is delegated and verified, the host match cannot succeed — so the
 * designer's bare `?route=/` link landed on the site's not-found page instead
 * of the page being edited (WOO-565, finding B21). The flip side is that a
 * `page.portal` value which has drifted from the serving portal's slug now
 * fails honestly instead of silently rendering another portal's site.
 *
 * WHY NO LOOKUP. A page object stores its portal BY SLUG (`page.portal`, e.g.
 * `open-tilburg`), and the slug is exactly what the resolver compares against
 * `site.slug`. Nothing has to be fetched to address the page.
 *
 * WHY THIS MODULE IMPORTS NOTHING. `generateUrl` is handed in, so
 * `node --test tests/page-layout-entry.spec.mjs` can exercise the builder as a
 * plain module. Same shape as `src/site/lib/authApi.js`.
 *
 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-designer-must-be-reachable-from-the-page-administration-surfaces
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
 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-designer-must-be-reachable-from-the-page-administration-surfaces
 */
export function pageSiteUrl(page, generateUrlFn) {
	const route = String(page?.route || '/')
	// encodeURIComponent, not a template literal: a slug or route carrying
	// `&`, `#`, a space or non-ASCII would otherwise split the query or
	// resolve to a different portal than the page belongs to.
	const base = `${generateUrlFn(SITE_PATH)}?route=${encodeURIComponent(route)}`
	const portal = String(page?.portal || '').trim()

	// A page with no portal cannot be addressed by slug at all. The bare route
	// is then the only option, and it is not useless: with no slug named, the
	// resolver falls through to the request HOST, which resolves on an instance
	// whose domain IS delegated.
	return portal === '' ? base : `${base}&portal=${encodeURIComponent(portal)}`
}

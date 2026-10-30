/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Which pages of portaliq's Nextcloud app the signed-in user may use.
 *
 * The server hands four flags (`DashboardController::page`, `AdminMenuAccess`)
 * and the manifest's menu entries name the flag they need as a `visibleIf`
 * predicate on `access.<flag>`. CnAppNav evaluates those predicates against
 * `manifest.runtime`, so this module puts the flags there, and the router
 * guard below reads the SAME predicates, so a page hidden from the menu is not
 * reachable by typing its address either. The server still refuses every
 * write the user may not make; the menu only stops offering it.
 *
 * @spec openspec/changes/admin-menu-follows-roles/specs/admin-ui/spec.md#requirement-the-app-menu-must-show-a-user-only-the-pages-their-role-may-use
 */

import { limitPortalsPage } from './portalAdminGroups.js'

/** The flags the server hands, in a fixed order. */
export const ACCESS_FLAGS = ['admin', 'pages', 'accounts', 'accessRequests', 'portals']

/** The prefix a menu predicate uses to name a flag. */
const PREFIX = 'access.'

/**
 * The flags as booleans: anything but `true` is false, and a missing answer
 * gives no administration at all. `portalSlugs` is the list of portals the
 * Portals page shows, or null for all of them; anything but a list or null
 * shows none.
 *
 * @param {unknown} raw What the server handed, or null.
 * @return {{admin: boolean, pages: boolean, accounts: boolean, accessRequests: boolean, portals: boolean, portalSlugs: Array<string>|null}}
 * @spec openspec/changes/admin-menu-follows-roles/specs/admin-ui/spec.md#requirement-the-app-menu-must-show-a-user-only-the-pages-their-role-may-use
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t12
 */
export function normaliseAccess(raw) {
	const source = raw && typeof raw === 'object' ? raw : {}
	const flags = Object.fromEntries(
		ACCESS_FLAGS.map((flag) => [flag, source[flag] === true]),
	)
	let portalSlugs = []
	if (source.portalSlugs === null && flags.portals) {
		portalSlugs = null
	} else if (Array.isArray(source.portalSlugs)) {
		portalSlugs = source.portalSlugs.filter((slug) => typeof slug === 'string' && slug !== '')
	}
	return { ...flags, portalSlugs }
}

/**
 * The manifest with the flags in `runtime.access`, where CnAppNav's
 * `visibleIf` predicates read them, and the Portals page limited to the
 * portals the user administers. The bundled manifest is not changed.
 *
 * @param {object} manifest The bundled manifest.
 * @param {object} access The normalised flags.
 * @return {object} A manifest carrying the flags.
 * @spec openspec/changes/admin-menu-follows-roles/specs/admin-ui/spec.md#requirement-the-app-menu-must-show-a-user-only-the-pages-their-role-may-use
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t12
 */
export function withAccess(manifest, access) {
	const slugs = access.portalSlugs === undefined ? null : access.portalSlugs
	return {
		...manifest,
		pages: (manifest.pages || []).map((page) =>
			page.id === 'Portals' ? limitPortalsPage(page, slugs) : page,
		),
		runtime: { ...(manifest.runtime || {}), access: { ...access } },
	}
}

/**
 * Whether a menu entry's access predicates pass. Only `access.<flag>` keys
 * are read here; every other `visibleIf` key is CnAppNav's to evaluate.
 *
 * @param {object} item A menu entry.
 * @param {object} access The normalised flags.
 * @return {boolean} True when every access predicate passes.
 * @spec openspec/changes/admin-menu-follows-roles/specs/admin-ui/spec.md#requirement-the-app-menu-must-show-a-user-only-the-pages-their-role-may-use
 */
export function entryAllowed(item, access) {
	const condition = item && item.visibleIf
	if (!condition || typeof condition !== 'object') {
		return true
	}
	return Object.entries(condition)
		.filter(([key]) => key.startsWith(PREFIX))
		.every(([key, expected]) => access[key.slice(PREFIX.length)] === expected)
}

/**
 * Whether the user may open the page a route names: the menu entry that
 * leads to it must pass. A page no menu entry leads to (a detail page, a
 * wizard) is left to the server.
 *
 * @param {object} manifest The manifest.
 * @param {string} routeName The route name (a manifest page id).
 * @param {object} access The normalised flags.
 * @return {boolean} True when the page may open.
 * @spec openspec/changes/admin-menu-follows-roles/specs/admin-ui/spec.md#requirement-the-app-menu-must-show-a-user-only-the-pages-their-role-may-use
 */
export function routeAllowed(manifest, routeName, access) {
	const entries = (manifest.menu || []).filter((item) => item.route === routeName)
	return entries.length === 0 || entries.some((item) => entryAllowed(item, access))
}

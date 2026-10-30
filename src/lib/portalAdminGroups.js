// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The logic behind a portal's "Administrator groups" card
// (operate-portals-per-organisation REQ-OPO-005): where it reads and writes,
// and the ids a picker selection saves.

/**
 * The route for one portal's administering groups.
 *
 * @param {string} slug The portal slug.
 * @param {(path: string) => string} generateUrl Nextcloud's URL generator.
 * @return {string} The URL.
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t12
 */
export function adminGroupsUrl(slug, generateUrl) {
	return generateUrl(
		`/apps/portaliq/api/portals/${encodeURIComponent(slug)}/admin-groups`,
	)
}

/**
 * The group ids of a picker selection: unique, non-empty, in picked order.
 *
 * @param {Array<object|string>} selection The picked `{id, label}` options or ids.
 * @return {Array<string>} The ids to save.
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t12
 */
export function groupIds(selection) {
	const ids = []
	for (const entry of selection || []) {
		const id = typeof entry === 'string' ? entry : entry && entry.id
		if (typeof id === 'string' && id.trim() !== '' && !ids.includes(id.trim())) {
			ids.push(id.trim())
		}
	}
	return ids
}

/**
 * Whether a selection differs from the stored groups, in any order.
 *
 * @param {Array<object|string>} stored The stored groups.
 * @param {Array<object|string>} selection The picked groups.
 * @return {boolean} True when saving would change the list.
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t12
 */
export function groupsChanged(stored, selection) {
	const before = groupIds(stored).sort()
	const after = groupIds(selection).sort()
	return JSON.stringify(before) !== JSON.stringify(after)
}

/**
 * The Portals page limited to the portals an admin group member administers.
 * `slugs` null means every portal, and leaves the page as it is.
 *
 * @param {object} page The Portals index page.
 * @param {Array<string>|null} slugs The administered portal slugs, or null.
 * @return {object} The page, with a fixed slug filter when limited.
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t12
 */
export function limitPortalsPage(page, slugs) {
	if (!Array.isArray(slugs)) {
		return page
	}
	const config = page.config || {}
	return {
		...page,
		config: {
			...config,
			filter: { ...(config.filter || {}), slug: [...slugs] },
		},
	}
}

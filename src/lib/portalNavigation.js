// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The logic behind a portal's "Navigation" section and a portal account's
// "Pages hidden for this client" field (operate-pages-per-portal-and-client):
// where they read and write, how a page moves up or down, and the shapes a
// save sends. No Nextcloud import, so tests/portal-navigation.spec.mjs can
// load it under plain node.

/**
 * The admin route for one portal's navigation.
 *
 * @param {string} slug The portal slug.
 * @param {(path: string) => string} generateUrl Nextcloud's URL generator.
 * @return {string} The URL.
 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-the-administrator-makes-the-portal-choice-on-the-portals-page-req-pgc-003
 */
export function navigationUrl(slug, generateUrl) {
	return generateUrl(
		`/apps/portaliq/api/portals/${encodeURIComponent(slug)}/navigation`,
	)
}

/**
 * The staff route for the pages hidden for one account.
 *
 * @param {string} subjectRef The account's subject reference.
 * @param {(path: string) => string} generateUrl Nextcloud's URL generator.
 * @return {string} The URL.
 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-client-sees-only-the-pages-and-records-left-to-them-req-pgc-002
 */
export function accountPagesUrl(subjectRef, generateUrl) {
	return generateUrl(
		`/apps/portaliq/api/accounts/${encodeURIComponent(subjectRef)}/pages`,
	)
}

/**
 * The list with the page at `index` moved one place up (-1) or down (+1).
 * A move past either end answers the list unchanged. The list given is
 * never changed.
 *
 * @param {Array<object>} pages The pages in their order.
 * @param {number} index The page to move.
 * @param {number} step -1 for up, +1 for down.
 * @return {Array<object>} The new order.
 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-the-administrator-makes-the-portal-choice-on-the-portals-page-req-pgc-003
 */
export function movePage(pages, index, step) {
	const list = [...(pages || [])]
	const target = index + step
	if (index < 0 || index >= list.length || target < 0 || target >= list.length) {
		return list
	}
	const [page] = list.splice(index, 1)
	list.splice(target, 0, page)
	return list
}

/**
 * The navigation a save sends: per audience, every page in its order as
 * `{ page, hidden }`.
 *
 * @param {Array<{audience: string, pages: Array<object>}>} audiences The section's audiences.
 * @return {Object<string, Array<{page: string, hidden: boolean}>>} The navigation.
 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-the-administrator-makes-the-portal-choice-on-the-portals-page-req-pgc-003
 */
export function navigationFrom(audiences) {
	const out = {}
	for (const group of audiences || []) {
		if (!group || !group.audience) {
			continue
		}
		out[group.audience] = (group.pages || [])
			.filter((page) => page && page.page)
			.map((page) => ({ page: page.page, hidden: page.hidden === true }))
	}
	return out
}

/**
 * The page keys hidden for an account, as a save sends them.
 *
 * @param {Array<object>} pages The listed pages with `hidden`.
 * @return {Array<string>} The hidden keys.
 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-client-sees-only-the-pages-and-records-left-to-them-req-pgc-002
 */
export function hiddenPagesFrom(pages) {
	return (pages || [])
		.filter((page) => page && page.page && page.hidden === true)
		.map((page) => page.page)
}

/**
 * A deep copy of the section's audiences, so edits never touch the stored state.
 *
 * @param {Array<object>} audiences The audiences as answered.
 * @return {Array<object>} The copy.
 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-the-administrator-makes-the-portal-choice-on-the-portals-page-req-pgc-003
 */
export function copyAudiences(audiences) {
	return (audiences || []).map((group) => ({
		...group,
		pages: (group.pages || []).map((page) => ({ ...page })),
	}))
}

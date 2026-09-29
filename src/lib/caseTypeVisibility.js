// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The logic behind a portal's "Case types" page (operate-show-per-case-type):
// which case types a save hides, whether it hides one that residents see
// now, and where the page reads and writes.

/**
 * The admin route for one portal's case types.
 *
 * @param {string} slug The portal slug.
 * @param {(path: string) => string} generateUrl Nextcloud's URL generator.
 * @return {string} The URL.
 * @spec openspec/changes/operate-show-per-case-type/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-hides-a-case-type-in-one-portal-req-osc-001
 */
export function caseTypesUrl(slug, generateUrl) {
	return generateUrl(
		`/apps/portaliq/api/portals/${encodeURIComponent(slug)}/case-types`,
	)
}

/**
 * The case types switched off, in the shape the portal stores.
 *
 * @param {Array<object>} rows The listed case types with `shown`.
 * @return {Array<object>} The hidden list.
 * @spec openspec/changes/operate-show-per-case-type/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-hides-a-case-type-in-one-portal-req-osc-001
 */
export function hiddenFrom(rows) {
	return (rows || [])
		.filter((row) => row && row.shown === false && row.typeId)
		.map((row) => ({
			register: row.register || '',
			schema: row.schema || '',
			typeId: row.typeId,
			label: row.label || '',
		}))
}

/**
 * Whether the edited list hides a case type the saved list shows, which is
 * when residents would lose sight of their cases and the page warns.
 *
 * @param {Array<object>} saved The list as stored.
 * @param {Array<object>} edited The list as switched now.
 * @return {boolean}
 * @spec openspec/changes/operate-show-per-case-type/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-hides-a-case-type-in-one-portal-req-osc-001
 */
export function hidesMore(saved, edited) {
	const shownBefore = new Set(
		(saved || []).filter((row) => row.shown !== false).map((row) => row.typeId),
	)
	return (edited || []).some(
		(row) => row.shown === false && shownBefore.has(row.typeId),
	)
}

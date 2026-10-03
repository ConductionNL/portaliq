// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// What /mijn shows (design D4), without Vue, so tests/mijn-home.spec.mjs runs
// it as node: the pages a contribution marks `home: true`, and without one an
// overview of every app's running cases.
//
// @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-mijn-must-open-on-what-the-resident-still-has-to-do-req-smo-007

/**
 * The pages a contribution marks `home: true`, in contribution order.
 *
 * @param {Array<object>} nav The signed-in navigation.
 * @return {Array<object>} Their entries.
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-mijn-must-open-on-what-the-resident-still-has-to-do-req-smo-007
 */
export function homeEntriesOf(nav) {
	return (Array.isArray(nav) ? nav : []).filter(
		(entry) => !entry.special && entry.page?.home === true,
	)
}

/**
 * Without a home page: one overview page per contribution with `cases`
 * collections, a `cases` block per collection, open cases only, four each.
 *
 * @param {Array<object>} contributions The aggregate's contributions.
 * @param {string} label The blocks' heading, "Lopende zaken".
 * @return {Array<object>} Navigation-like entries `{key, page, contribution}`.
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-mijn-must-open-on-what-the-resident-still-has-to-do-req-smo-007
 */
export function caseOverviewsOf(contributions, label) {
	return (Array.isArray(contributions) ? contributions : [])
		.map((contribution) => {
			const blocks = (contribution?.collections || [])
				.filter(
					(collection) => collection?.kind === 'cases' && collection.id,
				)
				.map((collection) => ({
					type: 'cases',
					collection: collection.id,
					open: true,
					limit: 4,
					label,
				}))
			return {
				key: `__home__:${contribution?.app || ''}`,
				page: { id: '__home__', blocks },
				contribution,
			}
		})
		.filter((overview) => overview.page.blocks.length > 0)
}

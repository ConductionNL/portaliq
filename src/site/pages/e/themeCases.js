// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The running cases of a theme and their count for the resident menu
// (life-domain-theme-pages), on their own so the site entry, which counts the
// cases for the menu, does not carry the rest of themes.js: the theme page
// loads on demand (public-bundle-budget). No Vue, so the node specs run it as
// a plain script.
//
// @spec openspec/changes/life-domain-theme-pages/specs/life-domain-themes/spec.md

import { caseStatus, caseTitle } from '../../../shared/myCases.js'
import { isClosedCase } from '../../components/mijn/closedCase.js'

/**
 * The running cases of a cases collection: every row but a closed one, as the
 * theme page lists them.
 *
 * @param {Array<object>} rows The collection's rows.
 * @param {object} collection The `cases` collection.
 * @return {Array<{id: string, title: string, status: string}>} The running cases.
 *
 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t10
 */
export function runningCases(rows, collection) {
	return (Array.isArray(rows) ? rows : [])
		.filter((row) => row && !isClosedCase(row, collection))
		.map((row) => ({
			id: String(row.id ?? row.uuid ?? ''),
			title: caseTitle(row),
			status: caseStatus(row),
		}))
}

/**
 * Every cases collection a contribution tagged with a theme, keyed as the
 * shell keeps known rows (`<app>:<collection>`).
 *
 * @param {Array<object>} contributions The aggregate's contributions.
 * @return {Array<{key: string, slug: string, collection: object}>} The collections.
 *
 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t10
 */
export function themeCaseCollections(contributions) {
	const out = []
	for (const contribution of Array.isArray(contributions) ? contributions : []) {
		for (const collection of contribution?.collections || []) {
			if (
				collection?.kind === 'cases'
				&& typeof collection.theme === 'string'
				&& collection.theme !== ''
				&& collection.id
			) {
				out.push({
					key: `${contribution.app || ''}:${collection.id}`,
					slug: collection.theme,
					collection,
				})
			}
		}
	}
	return out
}

/**
 * How many running cases each theme holds, for the menu: only themes whose
 * cases collections were read and hold a running case are counted.
 *
 * @param {Array<object>} contributions The aggregate's contributions.
 * @param {Record<string, Array<object>>} rows The known rows, by `<app>:<collection>`.
 * @return {Record<string, number>} Theme slug => running cases.
 *
 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t10
 */
export function themeCaseCounts(contributions, rows) {
	const counts = {}
	for (const { key, slug, collection } of themeCaseCollections(contributions)) {
		const running = runningCases(rows?.[key], collection).length
		if (running > 0) {
			counts[slug] = (counts[slug] || 0) + running
		}
	}
	return counts
}

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The tasks an app contributes to "My tasks": the rows of every collection a
 * contribution shows in a `tasks` block (dossiq's "Wat wij nog van u nodig
 * hebben"). The overview lists them as what the resident still has to do, so
 * "My tasks" lists them too, beside the portal tasks. No Vue, so
 * tests/tasks-page.spec.mjs runs it as node.
 *
 * @spec openspec/changes/mijn-taken-lists-contributed-tasks/specs/site-mijn-omgeving/spec.md#requirement-my-tasks-lists-what-the-apps-ask-of-the-resident
 */

import { taskRows } from '../../components/mijn/rows.js'
import { recordRoute } from './inbox.js'

/** As many rows as one collection read returns. */
const ALL_ROWS = 200

/**
 * Every collection a contribution shows in a `tasks` block, once per app and
 * collection, with the first block that shows it.
 *
 * @param {object|Array|null} aggregate The contributions aggregate, or its list.
 * @return {Array<{app: string, block: object, collection: object}>} The sources.
 * @spec openspec/changes/mijn-taken-lists-contributed-tasks/specs/site-mijn-omgeving/spec.md#requirement-my-tasks-lists-what-the-apps-ask-of-the-resident
 */
export function contributedTaskSources(aggregate) {
	const list = Array.isArray(aggregate)
		? aggregate
		: aggregate?.contributions || []
	const seen = new Set()
	const sources = []
	for (const contribution of list) {
		for (const page of contribution?.pages || []) {
			for (const block of page?.blocks || []) {
				if (block?.type !== 'tasks' || typeof block.collection !== 'string') {
					continue
				}
				const collection = (contribution.collections || []).find(
					(candidate) => candidate?.id === block.collection,
				)
				const key = `${contribution.app}:${block.collection}`
				if (!collection || seen.has(key)) {
					continue
				}
				seen.add(key)
				sources.push({ app: String(contribution.app || ''), block, collection })
			}
		}
	}
	return sources
}

/**
 * Whether a row matches the collection's `defaultFilters`: the rows the app
 * means the list to show (dossiq's open requests, not the answered ones).
 *
 * @param {object} row The row.
 * @param {object} collection The collection.
 * @return {boolean} True when every default filter holds.
 * @spec openspec/changes/mijn-taken-lists-contributed-tasks/specs/site-mijn-omgeving/spec.md#requirement-my-tasks-lists-what-the-apps-ask-of-the-resident
 */
export function matchesDefaultFilters(row, collection) {
	const filters = collection?.defaultFilters
	if (!filters || typeof filters !== 'object') {
		return true
	}
	return Object.entries(filters).every(
		([field, value]) => String(row?.[field] ?? '') === String(value),
	)
}

/**
 * One source's rows as "My tasks" entries: titled and due as its block says,
 * soonest first, each with the route of the page that shows it.
 *
 * @param {{app: string, block: object, collection: object}} source The source.
 * @param {Array<object>} rows The collection's rows, scoped to the resident.
 * @param {Array<object>} nav Every navigation entry.
 * @return {Array<{key: string, title: string, due: string, link: object, route: string}>} The entries.
 * @spec openspec/changes/mijn-taken-lists-contributed-tasks/specs/site-mijn-omgeving/spec.md#requirement-my-tasks-lists-what-the-apps-ask-of-the-resident
 */
export function contributedTaskEntries(source, rows, nav) {
	const open = (Array.isArray(rows) ? rows : []).filter((row) =>
		matchesDefaultFilters(row, source.collection),
	)
	return taskRows(
		open,
		{ ...source.block, limit: ALL_ROWS },
		source.collection,
	).map((entry) => {
		const link = {
			app: source.app,
			collection: source.collection.id,
			id: entry.id,
		}
		return {
			key: `${source.app}:${source.collection.id}:${entry.id || entry.title}`,
			title: entry.title,
			due: entry.due,
			link,
			route: entry.id ? recordRoute(nav, link) || '' : '',
		}
	})
}

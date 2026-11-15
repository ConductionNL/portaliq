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
 * @param {object|Array|null} aggregate The contributions aggregate, or its list.
 * @return {Array<object>} The contributions.
 */
function contributionsOf(aggregate) {
	return Array.isArray(aggregate) ? aggregate : aggregate?.contributions || []
}

/**
 * @param {object|null} page A contribution page.
 * @return {string} The collection of the record the page shows, or ''.
 */
function recordCollectionOf(page) {
	return page?.record?.collection || page?.records?.collection || ''
}

/**
 * @param {object} contribution The contribution.
 * @param {object|null} block A block of one of its pages.
 * @return {object|null} The collection a `tasks` block shows, or null.
 */
function taskCollectionOf(contribution, block) {
	if (block?.type !== 'tasks' || typeof block.collection !== 'string') {
		return null
	}
	return (
		(contribution.collections || []).find(
			(candidate) => candidate?.id === block.collection,
		) || null
	)
}

/**
 * The `tasks` blocks of one page whose collection exists, in page order.
 *
 * @param {object} contribution The contribution.
 * @param {object|null} page One of its pages.
 * @return {Array<{contribution: object, block: object, collection: object, recordCollection: string}>}
 */
function taskBlocksOfPage(contribution, page) {
	const recordCollection = recordCollectionOf(page)
	// Spread, not `.map` on the value itself: blocks are read the way a loop
	// reads them, whatever the page holds.
	return [...(page?.blocks || [])]
		.map((block) => ({
			contribution,
			block,
			collection: taskCollectionOf(contribution, block),
			recordCollection,
		}))
		.filter((found) => found.collection)
}

/**
 * The source of a task block's app and collection, made from the first block
 * that shows it.
 *
 * @param {Map<string, object>} byKey The sources so far, by app and collection.
 * @param {{contribution: object, block: object, collection: object}} found The block.
 * @return {object} The source.
 */
function sourceFor(byKey, found) {
	const { contribution, block, collection } = found
	const key = `${contribution.app}:${block.collection}`
	if (!byKey.has(key)) {
		byKey.set(key, {
			app: String(contribution.app || ''),
			block,
			collection,
			record: null,
		})
	}
	return byKey.get(key)
}

/**
 * Keep a task block's collection as a source once per app and collection,
 * and give the source the first record a block names.
 *
 * @param {Map<string, object>} byKey The sources so far, by app and collection.
 * @param {{contribution: object, block: object, collection: object, recordCollection: string}} found The block.
 */
function keepSource(byKey, found) {
	const source = sourceFor(byKey, found)
	const { block, recordCollection } = found
	if (!source.record && recordCollection && block.recordField) {
		source.record = {
			field: String(block.recordField),
			collection: recordCollection,
		}
	}
}

/**
 * Every collection a contribution shows in a `tasks` block, once per app and
 * collection, with the first block that shows it. A task block on a record
 * page that names its `recordField` says which record a row belongs to
 * (dossiq's request names its case), so a row can open that record.
 *
 * @param {object|Array|null} aggregate The contributions aggregate, or its list.
 * @return {Array<{app: string, block: object, collection: object, record: {field: string, collection: string}|null}>} The sources.
 * @spec openspec/changes/mijn-taken-lists-contributed-tasks/specs/site-mijn-omgeving/spec.md#requirement-my-tasks-lists-what-the-apps-ask-of-the-resident
 */
export function contributedTaskSources(aggregate) {
	const byKey = new Map()
	for (const contribution of contributionsOf(aggregate)) {
		for (const page of contribution?.pages || []) {
			taskBlocksOfPage(contribution, page).forEach((found) =>
				keepSource(byKey, found),
			)
		}
	}
	return [...byKey.values()]
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
 * @param {Array<object>} nav Every navigation entry.
 * @param {{app: string, collection: string, id: string}} link A record link.
 * @return {string} The route that opens the record, or ''.
 */
function routeOr(nav, link) {
	return recordRoute(nav, link) || ''
}

/**
 * Without a page of its own, a row opens the record it belongs to.
 *
 * @param {{app: string, record: {field: string, collection: string}|null}} source The source.
 * @param {{row: object}} entry The task row's entry.
 * @return {{app: string, collection: string, id: string}|null} The record, or null for none.
 */
function belongingRecord(source, entry) {
	const recordId = source.record ? entry.row?.[source.record.field] : ''
	return typeof recordId === 'string' && recordId !== ''
		? { app: source.app, collection: source.record.collection, id: recordId }
		: null
}

/**
 * One task row as a "My tasks" entry, linked to its own page, or else to the
 * record it belongs to.
 *
 * @param {{source: object, nav: Array<object>}} view The source and every navigation entry.
 * @param {{id: string, title: string, due: string, row: object}} entry The task row's entry.
 * @return {{key: string, title: string, due: string, link: object, route: string}} The entry.
 */
function taskEntryOf(view, entry) {
	const { source, nav } = view
	const own = { app: source.app, collection: source.collection.id, id: entry.id }
	const ownRoute = entry.id ? routeOr(nav, own) : ''
	const record = ownRoute ? null : belongingRecord(source, entry)
	return {
		key: `${source.app}:${source.collection.id}:${entry.id || entry.title}`,
		title: entry.title,
		due: entry.due,
		link: record || own,
		route: record ? routeOr(nav, record) : ownRoute,
	}
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
	const view = { source, nav }
	const open = (Array.isArray(rows) ? rows : []).filter((row) =>
		matchesDefaultFilters(row, source.collection),
	)
	return taskRows(
		open,
		{ ...source.block, limit: ALL_ROWS },
		source.collection,
	).map((entry) => taskEntryOf(view, entry))
}

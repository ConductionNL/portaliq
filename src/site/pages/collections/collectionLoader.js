// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// Loads the collections a contribution page shows, and loads them again after
// a write. The React portal kept this in App.jsx (399-463); here it is plain
// JavaScript that writes into a store the page hands it (a Vue `reactive`
// object on the site, a plain object in tests/site-collections.spec.mjs).
//
// Each entry of the store is `{loading, failed, objects}`, keyed by collection id. A
// load that finishes after a newer one for the same collection is dropped, so
// a slow first read cannot overwrite the row a create just added.
//
// @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-collections-must-reload-after-a-write-req-srp-016

import { rowFor } from '../../../shared/openRecord.js'

/**
 * The ids of the collections a page's blocks read: every `collection`,
 * `detail`, `kpi`, `tasks` and `cases` block, a documents block for each
 * record, every calendar source, and a record page's record collection.
 *
 * @param {object} page The contribution page.
 * @return {Array<string>}
 * @spec openspec/changes/documents-for-each-record/specs/site-mijn-omgeving/spec.md#requirement-a-documents-block-for-each-record-shows-one-group-per-record
 */
export function collectionIdsFor(page) {
	const ids = []
	const add = (id) => {
		if (typeof id === 'string' && id !== '' && !ids.includes(id)) {
			ids.push(id)
		}
	}
	// A record page reads its record collection first (contribution-record-page).
	add(page?.record?.collection)
	// A switching page reads its records and the collection its subtitle
	// comes from (site-mijn-omgeving-components REQ-SMO-008, REQ-SMO-026).
	add(page?.records?.collection)
	add(page?.records?.subtitleLookup?.collection)
	for (const block of page?.blocks || []) {
		blockCollectionIds(block).forEach(add)
	}
	return ids
}

/**
 * The collections one block reads, in the order it reads them: its own rows,
 * its lookups, its status (card-status-today) and a calendar's sources.
 *
 * @param {object|null} block A page block.
 * @return {Array<unknown>} The collection ids, unchecked.
 */
function blockCollectionIds(block) {
	return [
		readsRows(block) ? block.collection : undefined,
		...[...(block?.lookups || [])].map((lookup) => lookup?.collection),
		block?.status?.collection,
		...calendarSourceIds(block),
	]
}

/**
 * @param {object|null} block A page block.
 * @return {Array<unknown>} A calendar block's source collections, or none.
 */
function calendarSourceIds(block) {
	if (block?.type !== 'calendar') {
		return []
	}
	return [...(block.sources || [])].map((source) => source?.collection)
}

/**
 * Whether a block reads the rows of its own collection: a `collection`,
 * `detail`, `kpi`, `tasks` or `cases` block, or a documents block for each
 * record.
 *
 * @param {object} block The block.
 * @return {boolean}
 * @spec openspec/changes/documents-for-each-record/specs/site-mijn-omgeving/spec.md#requirement-a-documents-block-for-each-record-shows-one-group-per-record
 */
function readsRows(block) {
	return (
		['collection', 'detail', 'kpi', 'tasks', 'cases'].includes(block?.type)
		|| (block?.type === 'documents' && block.eachRecord === true)
	)
}

/**
 * A loader bound to one api and one store.
 *
 * @param {object} options The options.
 * @param {object} options.api The portal api (`fetchCollection`, `getContributions`, `updateObject`).
 * @param {object} options.store Where the rows go, keyed by collection id.
 * @return {object} The loader.
 */
export function createCollectionLoader({ api, store }) {
	const generation = new Map()

	/**
	 * Load one collection's rows, scoped to the resident by the server.
	 *
	 * @param {object} collection The collection.
	 * @return {Promise<void>}
	 */
	async function load(collection) {
		if (!canRead(collection)) {
			return
		}
		const turn = startRead(collection)
		const answer = await readRows(collection)
		// Only the latest read of a collection lands.
		if (generation.get(collection.id) === turn) {
			const failed = !Array.isArray(answer)
			store[collection.id] = {
				loading: false,
				failed,
				objects: failed ? [] : answer,
			}
		}
	}

	/**
	 * @param {object|null} collection The collection.
	 * @return {boolean} Whether it names an id and the api can read it.
	 */
	function canRead(collection) {
		return Boolean(
			collection
			&& collection.id
			&& api
			&& typeof api.fetchCollection === 'function',
		)
	}

	/**
	 * Mark a collection loading, keeping the rows it had, and count the read.
	 *
	 * @param {object} collection The collection.
	 * @return {number} This read's turn.
	 */
	function startRead(collection) {
		const turn = (generation.get(collection.id) || 0) + 1
		generation.set(collection.id, turn)
		store[collection.id] = {
			loading: true,
			failed: false,
			objects: store[collection.id]?.objects || [],
		}
		return turn
	}

	/**
	 * Read a collection's rows. A read that failed is not an empty list: it
	 * answers null, so the block can say so and offer to try again
	 * (site-mijn-omgeving-components REQ-SMO-009).
	 *
	 * @param {object} collection The collection.
	 * @return {Promise<Array<object>|null>} The rows, or null.
	 */
	async function readRows(collection) {
		try {
			return await api.fetchCollection(collection, { orNull: true })
		} catch {
			return null
		}
	}

	/**
	 * Load every collection a page reads.
	 *
	 * @param {object} page The contribution page.
	 * @param {object} contribution Its contribution.
	 * @return {Promise<void>}
	 */
	function loadPage(page, contribution) {
		const loads = []
		for (const id of collectionIdsFor(page)) {
			const collection = (contribution?.collections || []).find(
				(c) => c && c.id === id,
			)
			if (collection) {
				loads.push(load(collection))
			}
		}
		return Promise.all(loads).then(() => undefined)
	}

	/**
	 * @param {object|null} collection A collection.
	 * @param {{register: string, schema: string}|null} written What was written to.
	 * @return {boolean} Whether the collection reads that register and schema.
	 */
	function isWrittenTo(collection, written) {
		return Boolean(
			collection
			&& written
			&& collection.register === written.register
			&& collection.schema === written.schema,
		)
	}

	/**
	 * After a create or update: load again every collection, of every
	 * contribution, that reads the same register and schema, whether or not it
	 * has loaded before. Then read the unread count again, so a receipt the
	 * server dropped in the inbox shows on its badge.
	 *
	 * @param {Array<object>} contributions Every contribution.
	 * @param {{register: string, schema: string}} written What was written to.
	 * @return {Promise<number|null>} The fresh unread count, or null.
	 */
	async function afterWrite(contributions, written) {
		const loads = []
		for (const contribution of contributions || []) {
			for (const collection of contribution?.collections || []) {
				if (isWrittenTo(collection, written)) {
					loads.push(load(collection))
				}
			}
		}
		await Promise.all(loads)
		return refreshUnread()
	}

	/**
	 * The resident's unread count, read again from the aggregate.
	 *
	 * @return {Promise<number|null>}
	 */
	async function refreshUnread() {
		if (!api || typeof api.getContributions !== 'function') {
			return null
		}
		try {
			const fresh = await api.getContributions()
			return fresh && typeof fresh.unreadCount === 'number'
				? fresh.unreadCount
				: null
		} catch {
			return null
		}
	}

	/**
	 * A per-row status transition: run a `type: update` action on the row with
	 * no field data, so the server applies the action's own `set` values and the
	 * target cannot be changed here. Then load the collection again.
	 *
	 * @param {object} action The update action.
	 * @param {object} row The row.
	 * @param {object} collection Its collection.
	 * @return {Promise<boolean>} Whether a transition was sent.
	 */
	async function transition(action, row, collection) {
		const id = row && (row.id || row['@self']?.id)
		if (!id || !api || typeof api.updateObject !== 'function') {
			return false
		}
		try {
			await api.updateObject(action, id, {})
		} finally {
			await load(collection)
		}
		return true
	}

	return { load, loadPage, afterWrite, refreshUnread, transition }
}

/**
 * Where a record link stands on a page whose collection has loaded: the row
 * to select, or that it is not in the resident's own list. A case opened from
 * "My cases" under a mandate is not in the person's own rows, so the row the
 * link carries is used then (cases-my-cases-page REQ-CMC-005).
 *
 * @param {object|null} loaded The store entry of the target's collection.
 * @param {{collection: string, id: string, row?: object}} target The record link.
 * @return {{state: 'waiting'}|{state: 'found', row: object}|{state: 'missing'}}
 */
export function openRecordState(loaded, target) {
	if (!target || !loaded || loaded.loading) {
		return { state: 'waiting' }
	}
	const row = rowFor(loaded.objects, target.id) || target.row || null
	return row ? { state: 'found', row } : { state: 'missing' }
}

/**
 * Whether the open record was read under a mandate (`_mandate.id`). A case
 * read under a mandate is read-only (REQ-CMC-004): the server refuses every
 * write under it, so the page offers none.
 *
 * @param {object|null} record The open record.
 * @return {boolean}
 * @spec openspec/changes/mandated-case-offers-no-writes/specs/portal-my-cases/spec.md#requirement-a-case-read-under-a-mandate-offers-no-writes
 */
export function readUnderMandate(record) {
	const id = record?._mandate?.id
	return typeof id === 'string' && id !== ''
}

/**
 * The record a record page opens: the picked row when it is in the resident's
 * own list, or the picked row a mandate opened. A case opened from "My cases"
 * under a mandate is the represented party's, so it is never in the person's
 * own rows; its row carries the mandate (`_mandate.id`) it was read under, and
 * the blocks on the page read it again under that mandate. Any other row
 * outside the list opens nothing.
 *
 * @param {Array<object>} rows The record collection's scoped rows.
 * @param {object|null} picked The row picked for the page.
 * @return {object|null} The open record, or null.
 * @spec openspec/changes/mandated-case-opens-on-its-record-page/specs/portal-my-cases/spec.md#requirement-a-case-read-under-a-mandate-opens-on-its-record-page
 */
export function pickedRecord(rows, picked) {
	const id = picked?.id || picked?.uuid || picked?.['@self']?.id
	if (!id) {
		return null
	}
	return (
		rowFor(rows, id)
		|| (typeof picked._mandate?.id === 'string' && picked._mandate.id !== ''
			? picked
			: null)
	)
}

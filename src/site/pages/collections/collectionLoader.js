// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// Loads the collections a contribution page shows, and loads them again after
// a write. The React portal kept this in App.jsx (399-463); here it is plain
// JavaScript that writes into a store the page hands it (a Vue `reactive`
// object on the site, a plain object in tests/site-collections.spec.mjs).
//
// Each entry of the store is `{loading, objects}`, keyed by collection id. A
// load that finishes after a newer one for the same collection is dropped, so
// a slow first read cannot overwrite the row a create just added.
//
// @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-collections-must-reload-after-a-write-req-srp-016

import { rowFor } from '../../../shared/openRecord.js'

/**
 * The ids of the collections a page's blocks read: every `collection`,
 * `detail`, `kpi` and `tasks` block, every calendar source, and a record page's
 * record collection.
 *
 * @param {object} page The contribution page.
 * @return {Array<string>}
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
	for (const block of page?.blocks || []) {
		if (['collection', 'detail', 'kpi', 'tasks'].includes(block?.type)) {
			add(block.collection)
		}
		for (const lookup of block?.lookups || []) {
			add(lookup?.collection)
		}
		if (block?.type === 'calendar') {
			for (const source of block.sources || []) {
				add(source?.collection)
			}
		}
	}
	return ids
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
		if (
			!collection
			|| !collection.id
			|| !api
			|| typeof api.fetchCollection !== 'function'
		) {
			return
		}
		const turn = (generation.get(collection.id) || 0) + 1
		generation.set(collection.id, turn)
		store[collection.id] = {
			loading: true,
			objects: store[collection.id]?.objects || [],
		}
		let objects
		try {
			const answer = await api.fetchCollection(collection)
			objects = Array.isArray(answer) ? answer : []
		} catch {
			objects = []
		}
		if (generation.get(collection.id) === turn) {
			store[collection.id] = { loading: false, objects }
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
				if (
					collection
					&& written
					&& collection.register === written.register
					&& collection.schema === written.schema
				) {
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

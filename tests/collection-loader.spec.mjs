#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// collection-loader.spec.mjs: a contribution page on the site loads the
// collections its blocks read, and loads them again after a write
// (site-reaches-portal-parity REQ-SRP-016, REQ-SRP-021).
//
// Usage:
//   node --test tests/collection-loader.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	collectionIdsFor,
	createCollectionLoader,
	openRecordState,
} from '../src/site/pages/collections/collectionLoader.js'

/**
 * A portal api stand-in that answers each collection read from `rows`.
 *
 * @param {object} rows Rows by collection id.
 * @param {object} [extra] More methods.
 * @return {object} The api and its calls.
 */
function fakeApi(rows, extra = {}) {
	const calls = []
	return {
		calls,
		fetchCollection: async (collection) => {
			calls.push(['fetch', collection.id])
			return rows[collection.id] || []
		},
		getContributions: async () => {
			calls.push(['contributions'])
			return { unreadCount: 3 }
		},
		updateObject: async (action, id, body) => {
			calls.push(['update', action.id, id, body])
			return { ok: true }
		},
		...extra,
	}
}

const absences = { id: 'absences', register: 'learniq', schema: 'excuse-request' }
const children = { id: 'children', register: 'learniq', schema: 'learner-profile' }
const contribution = { app: 'learniq', collections: [absences, children] }

test('a page loads every collection a table or a detail reads, once each', async () => {
	const page = {
		blocks: [
			{ type: 'richText', markdown: 'x' },
			{ type: 'collection', collection: 'absences' },
			{ type: 'detail', collection: 'absences' },
			{ type: 'detail', collection: 'children' },
			{ type: 'action', action: 'create' },
		],
	}
	assert.deepEqual(collectionIdsFor(page), ['absences', 'children'])

	const api = fakeApi({ absences: [{ id: 'a1' }], children: [{ id: 'c1' }] })
	const store = {}
	await createCollectionLoader({ api, store }).loadPage(page, contribution)

	assert.deepEqual(store.absences, {
		loading: false,
		failed: false,
		objects: [{ id: 'a1' }],
	})
	assert.deepEqual(store.children, {
		loading: false,
		failed: false,
		objects: [{ id: 'c1' }],
	})
	assert.equal(api.calls.filter(([kind]) => kind === 'fetch').length, 2)
})

test('after a write every collection on that schema loads again, loaded before or not, and the unread count refreshes', async () => {
	const otherApp = {
		app: 'dossiq',
		collections: [{ id: 'mine', register: 'learniq', schema: 'excuse-request' }],
	}
	const api = fakeApi({
		absences: [{ id: 'a1' }, { id: 'a2' }],
		mine: [{ id: 'm1' }],
	})
	const store = {}
	const unread = await createCollectionLoader({ api, store }).afterWrite(
		[contribution, otherApp],
		{
			register: 'learniq',
			schema: 'excuse-request',
		},
	)

	assert.equal(store.absences.objects.length, 2, 'the new row is in the table')
	assert.equal(
		store.mine.objects.length,
		1,
		'a collection never loaded before loads too',
	)
	assert.equal(store.children, undefined, 'another schema is left alone')
	assert.equal(unread, 3)
})

test('a slow first read cannot overwrite the rows a later read brought', async () => {
	let release
	const slow = new Promise((resolve) => {
		release = resolve
	})
	let first = true
	const api = {
		fetchCollection: async () => {
			if (first) {
				first = false
				await slow
				return [{ id: 'old' }]
			}
			return [{ id: 'old' }, { id: 'new' }]
		},
	}
	const store = {}
	const loader = createCollectionLoader({ api, store })
	const firstLoad = loader.load(absences)
	await loader.load(absences)
	release()
	await firstLoad

	assert.deepEqual(
		store.absences.objects.map((row) => row.id),
		['old', 'new'],
	)
})

test('a failed read is marked failed, not an empty list, and not a page stuck loading', async () => {
	// site-mijn-omgeving-components REQ-SMO-009: a list that could not be
	// read says so; it never reads as "nothing here".
	const store = {}
	await createCollectionLoader({
		api: {
			fetchCollection: async () => {
				throw new Error('offline')
			},
		},
		store,
	}).load(absences)
	assert.deepEqual(store.absences, { loading: false, failed: true, objects: [] })

	const asked = []
	const refused = {}
	await createCollectionLoader({
		api: {
			fetchCollection: async (collection, options) => {
				asked.push(options)
				return null
			},
		},
		store: refused,
	}).load(absences)
	assert.deepEqual(refused.absences, { loading: false, failed: true, objects: [] })
	assert.deepEqual(asked, [{ orNull: true }], 'it asks for null on a refused read')
})

test('a status transition sends no field data and loads the collection again', async () => {
	const api = fakeApi({ absences: [{ id: 'a1', lifecycle: 'withdrawn' }] })
	const store = {}
	const sent = await createCollectionLoader({ api, store }).transition(
		{ id: 'withdraw', type: 'update' },
		{ '@self': { id: 'a1' } },
		absences,
	)

	assert.equal(sent, true)
	assert.deepEqual(api.calls[0], ['update', 'withdraw', 'a1', {}])
	assert.equal(store.absences.objects[0].lifecycle, 'withdrawn')
})

test("a record link selects the row from the resident's own list, or says it is not there", () => {
	const target = { app: 'learniq', collection: 'absences', id: 'a2' }

	assert.deepEqual(openRecordState(undefined, target), { state: 'waiting' })
	assert.deepEqual(openRecordState({ loading: true, objects: [] }, target), {
		state: 'waiting',
	})
	assert.deepEqual(
		openRecordState(
			{ loading: false, objects: [{ id: 'a1' }, { id: 'a2' }] },
			target,
		),
		{ state: 'found', row: { id: 'a2' } },
	)
	assert.deepEqual(
		openRecordState({ loading: false, objects: [{ id: 'a1' }] }, target),
		{ state: 'missing' },
	)
	// A case opened from "My cases" under a mandate carries the row it read.
	assert.deepEqual(
		openRecordState(
			{ loading: false, objects: [] },
			{ ...target, row: { id: 'a2', mandate: true } },
		),
		{
			state: 'found',
			row: { id: 'a2', mandate: true },
		},
	)
})

#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// collection-loader-guards.spec.mjs: what the collection loader does without
// a collection, an id or an api, the loading state it shows while a read is
// out (keeping the rows it had), and what a throwing read leaves, pinned
// before its load was split into named steps.
//
// Usage:
//   node --test tests/collection-loader-guards.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createCollectionLoader } from '../src/site/pages/collections/collectionLoader.js'

test('nothing is read or stored without a collection, an id or an api that reads', async () => {
	const store = {}
	for (const [api, collection] of [
		[{ fetchCollection: async () => [] }, null],
		[{ fetchCollection: async () => [] }, {}],
		[null, { id: 'c' }],
		[{ fetchCollection: 'x' }, { id: 'c' }],
	]) {
		await createCollectionLoader({ api, store }).load(collection)
	}
	assert.deepEqual(store, {})
})

test('while a read is out the rows stay, then the answer or the failure lands', async () => {
	const store = { c: { loading: false, failed: false, objects: [{ id: 1 }] } }
	let release
	const asked = []
	const api = {
		fetchCollection: (collection, options) => {
			asked.push([collection.id, options])
			return new Promise((resolve) => {
				release = resolve
			})
		},
	}
	const loader = createCollectionLoader({ api, store })
	const pending = loader.load({ id: 'c' })
	assert.deepEqual(store.c, { loading: true, failed: false, objects: [{ id: 1 }] })
	release([{ id: 2 }])
	await pending
	assert.deepEqual(store.c, { loading: false, failed: false, objects: [{ id: 2 }] })
	assert.deepEqual(asked, [['c', { orNull: true }]])

	const throwing = createCollectionLoader({
		api: { fetchCollection: async () => { throw new Error('offline') } },
		store,
	})
	await throwing.load({ id: 'd' })
	assert.deepEqual(store.d, { loading: false, failed: true, objects: [] })
	await createCollectionLoader({ api: { fetchCollection: async () => 'x' }, store }).load({ id: 'e' })
	assert.deepEqual(store.e, { loading: false, failed: true, objects: [] })
})

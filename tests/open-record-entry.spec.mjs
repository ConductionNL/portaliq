#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// open-record-entry.spec.mjs: which navigation entry a record link opens,
// with the location, history and storage given or taken from the browser
// (also when its storage refuses), and when the link is forgotten, pinned
// before openRecordEntry was split into named steps.
//
// Usage:
//   node --test tests/open-record-entry.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { openRecordEntry } from '../src/site/pages/collections/index.js'

const NAV = [
	{ key: 'dossiq:zaken', contribution: { app: 'dossiq' }, page: { blocks: [{ type: 'collection', collection: 'zaken' }] } },
]
const KEY = 'portaliq.openRecord'

/**
 * A storage double.
 *
 * @param {object} items What it holds.
 * @return {object} The storage, with `items` and `log`.
 */
function storage(items = {}) {
	const log = []
	return {
		items,
		log,
		getItem: (key) => (log.push(['get', key]), items[key] ?? null),
		setItem: (key, value) => (log.push(['set', key]), (items[key] = value)),
		removeItem: (key) => (log.push(['remove', key]), delete items[key]),
	}
}

test('a link opens the entry that shows its collection', () => {
	const kept = storage()
	const replaced = []
	const entry = openRecordEntry(NAV, {
		location: { hash: '#open=dossiq/zaken/z-1', pathname: '/site', search: '?p=1' },
		history: { replaceState: (...args) => replaced.push(args) },
		storage: kept,
	})
	assert.equal(entry, NAV[0])
	assert.deepEqual(replaced, [[null, '', '/site?p=1']])
	assert.deepEqual(kept.log, [['set', KEY]])
})

test('a link no page shows is forgotten; no link opens nothing', () => {
	const kept = storage({ [KEY]: JSON.stringify({ app: 'other', collection: 'x', id: '1' }) })
	assert.equal(openRecordEntry(NAV, { location: { hash: '' }, history: null, storage: kept }), null)
	assert.equal(Object.hasOwn(kept.items, KEY), false)
	assert.equal(openRecordEntry(NAV, { location: { hash: '' }, history: null, storage: storage() }), null)
	assert.equal(openRecordEntry(null, { location: { hash: '#open=dossiq/zaken/z' }, history: null, storage: null }), null)
})

test('without a where, the browser gives the location, history and storage', () => {
	const kept = storage()
	const replaced = []
	globalThis.window = {
		location: { hash: '#open=dossiq/zaken/z-2', pathname: '/s', search: '' },
		history: { replaceState: (...args) => replaced.push(args) },
		sessionStorage: kept,
	}
	assert.equal(openRecordEntry(NAV), NAV[0])
	assert.deepEqual(replaced, [[null, '', '/s']])
	assert.deepEqual(kept.log, [['set', KEY]])
	globalThis.window = {
		location: { hash: '#open=dossiq/zaken/z-3', pathname: '/s', search: '' },
		history: null,
		get sessionStorage() {
			throw new Error('blocked')
		},
	}
	assert.equal(openRecordEntry(NAV), NAV[0])
	const given = storage()
	assert.equal(openRecordEntry(NAV, { storage: given }), NAV[0])
	assert.deepEqual(given.log, [['set', KEY]])
	assert.equal(openRecordEntry(NAV, { storage: null, location: { hash: '' } }), null)
	delete globalThis.window
	assert.equal(openRecordEntry(NAV), null)
})

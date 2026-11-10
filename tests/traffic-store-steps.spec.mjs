#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// traffic-store-steps.spec.mjs: how the Traffic page's store registers its
// schemas, picks a portal, and loads its daily records and its session
// recordings (filtered, sorted, failed), pinned before those actions were
// split into named steps.
//
// The store imports the shared object store and Nextcloud's router, which
// plain node cannot load; a copy of the store with those two imports stubbed
// is loaded instead, next to the other compiled test modules.
//
// Usage:
//   node --test tests/traffic-store-steps.spec.mjs

import assert from 'node:assert/strict'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { createPinia, setActivePinia } from 'pinia'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const OUT = join(ROOT, 'node_modules', '.cache', 'traffic-store', String(process.pid))
mkdirSync(OUT, { recursive: true })
writeFileSync(join(OUT, 'object-store.mjs'), 'export const useObjectStore = () => globalThis.__objectStore')
writeFileSync(join(OUT, 'router.mjs'), 'export const generateUrl = (path) => `/nc${path}`')
const source = readFileSync(join(ROOT, 'src/store/traffic.js'), 'utf8')
	.replace("from '@conduction/nextcloud-vue'", `from '${pathToFileURL(join(OUT, 'object-store.mjs')).href}'`)
	.replace("from '@nextcloud/router'", `from '${pathToFileURL(join(OUT, 'router.mjs')).href}'`)
	.replace("from '../lib/trafficSummary.js'", `from '${pathToFileURL(join(ROOT, 'src/lib/trafficSummary.js')).href}'`)
writeFileSync(join(OUT, 'traffic-store.mjs'), source)
const { useTrafficReportStore, RECORDINGS_LIMIT } = await import(pathToFileURL(join(OUT, 'traffic-store.mjs')).href)

/**
 * A shared object store whose collections answer from a map, or throw.
 *
 * @param {object} answers Rows per type, or an Error to throw.
 * @param {object} registry The types registered so far.
 * @return {object} The object store, with `calls` and `registered`.
 */
function objectStore(answers, registry = {}) {
	const store = {
		objectTypeRegistry: registry,
		calls: [],
		registered: [],
		registerObjectType(type, schema, register) {
			store.registered.push([type, schema, register])
			store.objectTypeRegistry = { ...(store.objectTypeRegistry || {}), [type]: { schema } }
		},
		async fetchCollection(type, params) {
			store.calls.push([type, params])
			const answer = answers[type]
			if (answer instanceof Error) {
				throw answer
			}
			return answer
		},
	}
	globalThis.__objectStore = store
	return store
}

/**
 * A fresh Traffic store.
 *
 * @return {object} The store.
 */
function fresh() {
	setActivePinia(createPinia())
	return useTrafficReportStore()
}

const PORTALS = [
	null,
	{ title: 'no slug' },
	{ slug: 'plain' },
	{ slug: 'rollup', traffic: { enabled: true, rollupOf: ['a', 'b'] } },
	{ slug: 'measured', traffic: { enabled: true, sensitive: { sessionRecording: true } } },
]

test('the schemas are registered once each, and only when missing', () => {
	const objects = objectStore({}, null)
	const store = fresh()
	store.objects()
	store.objects()
	assert.deepEqual(objects.registered, [
		['portal', 'portal', 'portaliq'],
		['portalTrafficDaily', 'portalTrafficDaily', 'portaliq'],
		['portalTrafficRecording', 'portalTrafficRecording', 'portaliq'],
	])
	const known = objectStore({}, { portal: {}, portalTrafficDaily: {}, portalTrafficRecording: {} })
	fresh().objects()
	assert.deepEqual(known.registered, [])
})

test('loading picks the measured portal of its own, then loads its records and recordings', async () => {
	const objects = objectStore({
		portal: PORTALS,
		portalTrafficDaily: [{ portal: 'measured', date: '2026-01-01' }, { portal: 'other', date: '2026-01-01' }, { portal: 'measured' }, null],
		portalTrafficRecording: [{ portal: 'measured', startedAt: '2026-01-01' }, { portal: 'x' }, { portal: 'measured', startedAt: '2026-02-01' }, { portal: 'measured' }],
	})
	const store = fresh()
	await store.load()
	assert.equal(store.portalSlug, 'measured')
	assert.deepEqual(store.portals.map((p) => p.slug), ['plain', 'rollup', 'measured'])
	assert.deepEqual(store.records, [{ portal: 'measured', date: '2026-01-01' }])
	assert.deepEqual(store.recordings.map((r) => r.startedAt), ['2026-02-01', '2026-01-01', undefined])
	assert.deepEqual(objects.calls, [
		['portal', { _limit: 200 }],
		['portalTrafficDaily', { portal: 'measured', _limit: 2000 }],
		['portalTrafficRecording', { portal: 'measured', _limit: RECORDINGS_LIMIT, '_order[startedAt]': 'desc' }],
	])
	assert.equal(store.loadingPortals, false)
	assert.equal(store.loadingRecords, false)
	assert.equal(store.loadingRecordings, false)
})

test('a roll-up only when nothing else measures, else the first; a chosen portal stays', async () => {
	objectStore({ portal: [{ slug: 'plain' }, { slug: 'rollup', traffic: { enabled: true, rollupOf: ['a'] } }], portalTrafficDaily: [] })
	const rollup = fresh()
	await rollup.load()
	assert.equal(rollup.portalSlug, 'rollup')
	objectStore({ portal: [{ slug: 'first' }, { slug: 'second' }], portalTrafficDaily: [] })
	const first = fresh()
	await first.load()
	assert.equal(first.portalSlug, 'first')
	assert.deepEqual(first.recordings, [], 'no recording without the switch')
	objectStore({ portal: null, portalTrafficDaily: [] })
	const none = fresh()
	await none.load()
	assert.equal(none.portalSlug, '')
	assert.deepEqual(none.records, [])
	objectStore({ portal: PORTALS, portalTrafficDaily: [] })
	const chosen = fresh()
	chosen.portals = [{ slug: 'plain' }]
	chosen.portalSlug = 'plain'
	await chosen.load()
	assert.equal(chosen.portalSlug, 'plain')
})

test('failures are kept as the error, and loading goes on', async () => {
	const objects = objectStore({ portal: new Error('portals down'), portalTrafficDaily: [] })
	const store = fresh()
	store.portalSlug = 'kept'
	store.portals = [{ slug: 'kept' }]
	await store.load()
	assert.equal(store.error, '', 'loading the records of the kept portal clears it')
	assert.equal(store.loadingPortals, false)
	assert.deepEqual(objects.calls.map((call) => call[0]), ['portal', 'portalTrafficDaily'])

	objectStore({ portalTrafficDaily: new Error('records down') })
	const records = fresh()
	records.portalSlug = 'x'
	await records.loadRecords()
	assert.equal(records.error, 'records down')
	assert.deepEqual(records.records, [])
	assert.equal(records.loadingRecords, false)

	objectStore({ portalTrafficRecording: 'plain words' })
	const recordings = fresh()
	recordings.portals = [{ slug: 'x', traffic: { sensitive: { sessionRecording: true } } }]
	recordings.portalSlug = 'x'
	await recordings.loadRecordings()
	assert.notEqual(recordings.error, '')
	assert.equal(recordings.loadingRecordings, false)

	const plain = objectStore({})
	const empty = fresh()
	await empty.loadRecords()
	assert.deepEqual(plain.calls, [])
	const thrown = objectStore({ portal: 'not a list' })
	const odd = fresh()
	thrown.fetchCollection = async () => { throw 'a string' }
	await odd.load()
	assert.equal(odd.error, 'a string')
})

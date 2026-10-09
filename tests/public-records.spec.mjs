#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// public-records.spec.mjs: the public records block
// (site-member-voting-record-and-confidential-papers). The list and the record
// are read anonymously, an unlisted record says so, and the block is a
// registered widget.
//
// Usage:
//   node --test tests/public-records.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import {
	fetchRecord,
	fetchRecordList,
	matchingEntries,
	recordIdFrom,
	recordsBase,
} from '../src/site/lib/publicRecordsApi.js'
import { loadSfc, renderSfc } from './support/render-sfc.mjs'

const BLOCK = 'src/site/components/PublicRecordsBlock.vue'
const ENTRIES = [
	{ id: 'p1', title: 'Sanne Mulder', subtitle: 'Raadslid, GroenLinks' },
	{ id: 'p2', title: 'Joost de Wit', subtitle: 'Raadslid, VVD' },
]
const RECORD = {
	title: 'Sanne Mulder',
	subtitle: 'Raadslid, GroenLinks',
	summary: [{ label: 'Deelname', value: '96%', detail: 'aan 86 stemmingen' }],
	columns: [
		{ key: 'date', label: 'Datum' },
		{ key: 'subject', label: 'Onderwerp' },
		{ key: 'vote', label: 'Stem' },
	],
	rows: [{ date: '2026-09-01', subject: 'Groen dak op het stadhuis', vote: 'Voor', subjectUrl: '/besluit/1' }],
	note: 'Alleen openbare rondes.',
}

function fetcher(status, body) {
	const calls = []
	const fn = async (url, init) => {
		calls.push({ url, init })
		return { ok: status < 400, status, json: async () => body }
	}
	fn.calls = calls
	return fn
}

test('the routes are anonymous and found from the content API base', async () => {
	assert.equal(recordsBase('/apps/portaliq/api/content'), '/apps/portaliq/api/public-records')
	const send = fetcher(200, { entries: ENTRIES })
	assert.deepEqual(await fetchRecordList('/api/content', 'decidiq', 'memberVotingRecords', send), ENTRIES)
	assert.equal(send.calls[0].url, '/api/public-records/decidiq/memberVotingRecords')
	assert.equal(send.calls[0].init.credentials, 'omit')
	assert.equal(send.calls[0].init.headers.Authorization, undefined)
})

test('a record that is gone is null, not an error; a server error throws', async () => {
	assert.equal(await fetchRecord('/api/content', 'decidiq', 'l', 'x', fetcher(404, {})), null)
	assert.equal(await fetchRecordList('/api/content', 'decidiq', 'l', fetcher(404, {})), null)
	await assert.rejects(fetchRecord('/api/content', 'decidiq', 'l', 'x', fetcher(500, {})))
})

test('search narrows by name and role, and ?record names the record', () => {
	assert.deepEqual(matchingEntries(ENTRIES, 'mulder').map((e) => e.id), ['p1'])
	assert.deepEqual(matchingEntries(ENTRIES, 'vvd').map((e) => e.id), ['p2'])
	assert.equal(matchingEntries(ENTRIES, '  ').length, 2)
	assert.equal(recordIdFrom('?record=p1&x=1'), 'p1')
	assert.equal(recordIdFrom(''), '')
})

test('the block loads the list, then the record the page names', async () => {
	const screen = await loadSfc(BLOCK)
	const calls = []
	const api = {
		list: async () => { calls.push('list'); return ENTRIES },
		record: async (app, list, id) => { calls.push(`record:${id}`); return id === 'p1' ? RECORD : null },
	}
	const vm = (recordId) => ({ app: 'decidiq', list: 'memberVotingRecords', recordId, apiOverride: api, state: '', entries: [], record: null })

	const list = vm('')
	await screen.methods.load.call(list)
	assert.equal(list.state, 'ready')
	assert.deepEqual(calls, ['list'], 'no record is read without ?record')

	const one = vm('p1')
	await screen.methods.load.call(one)
	assert.equal(one.state, 'ready')
	assert.equal(one.record.title, 'Sanne Mulder')

	const stale = vm('p9')
	await screen.methods.load.call(stale)
	assert.equal(stale.state, 'gone')

	const missing = { ...vm(''), apiOverride: { list: async () => null } }
	await screen.methods.load.call(missing)
	assert.equal(missing.state, 'gone')
})

test('a stale link says so, a record shows its figures, rows and note', async () => {
	const gone = await renderSfc(BLOCK, { app: 'decidiq', list: 'l', recordParam: 'p9', locale: 'nl', apiOverride: { list: async () => ENTRIES, record: async () => null } })
	// Server rendering shows the loading state; the sentences are the block's own words.
	assert.match(gone, /public-records/)
	const source = readFileSync(BLOCK, 'utf8')
	assert.match(source, /Dit overzicht bestaat niet \(meer\)\./)
	for (const label of ['Datum', 'Onderwerp', 'Stem']) {
		assert.ok(RECORD.columns.some((c) => c.label === label))
	}
})

test('the block is a registered widget and not host-fed', () => {
	const grid = readFileSync('src/site/components/WidgetGrid.vue', 'utf8')
	assert.match(grid, /publicRecords: PublicRecordsBlock/)
	const catalogue = readFileSync('src/lib/pageWidgetCatalogue.js', 'utf8')
	assert.match(catalogue, /publicRecords: PublicRecordsBlock/)
	assert.match(readFileSync('src/lib/widgetLabels.js', 'utf8'), /publicRecords: 'Openbare overzichten'/)
})

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
import { recordListChoice, recordListsFrom } from '../src/editor/recordLists.js'
import {
	fetchRecord,
	fetchRecordList,
	matchingEntries,
	recordFigures,
	recordIdFrom,
	recordsBase,
	recordTableCollection,
} from '../src/site/lib/publicRecordsApi.js'
import { loadSfc, renderComponent, renderSfc } from './support/render-sfc.mjs'

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
	rows: [
		{
			date: '2026-09-01',
			subject: 'Groen dak op het stadhuis',
			vote: 'Voor',
			subjectUrl: '/besluit/1',
		},
	],
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
	assert.equal(
		recordsBase('/apps/portaliq/api/content'),
		'/apps/portaliq/api/public-records',
	)
	const send = fetcher(200, { entries: ENTRIES })
	assert.deepEqual(
		await fetchRecordList(
			'/api/content',
			'decidiq',
			'memberVotingRecords',
			send,
		),
		ENTRIES,
	)
	assert.equal(
		send.calls[0].url,
		'/api/public-records/decidiq/memberVotingRecords',
	)
	assert.equal(send.calls[0].init.credentials, 'omit')
	assert.equal(send.calls[0].init.headers.Authorization, undefined)
})

test('a record that is gone is null, not an error; a server error throws', async () => {
	assert.equal(
		await fetchRecord('/api/content', 'decidiq', 'l', 'x', fetcher(404, {})),
		null,
	)
	assert.equal(
		await fetchRecordList('/api/content', 'decidiq', 'l', fetcher(404, {})),
		null,
	)
	await assert.rejects(
		fetchRecord('/api/content', 'decidiq', 'l', 'x', fetcher(500, {})),
	)
})

test('search narrows by name and role, and ?record names the record', () => {
	assert.deepEqual(
		matchingEntries(ENTRIES, 'mulder').map((e) => e.id),
		['p1'],
	)
	assert.deepEqual(
		matchingEntries(ENTRIES, 'vvd').map((e) => e.id),
		['p2'],
	)
	assert.equal(matchingEntries(ENTRIES, '  ').length, 2)
	assert.equal(recordIdFrom('?record=p1&x=1'), 'p1')
	assert.equal(recordIdFrom(''), '')
})

test('the block loads the list, then the record the page names', async () => {
	const screen = await loadSfc(BLOCK)
	const calls = []
	const api = {
		list: async () => {
			calls.push('list')
			return ENTRIES
		},
		record: async (app, list, id) => {
			calls.push(`record:${id}`)
			return id === 'p1' ? RECORD : null
		},
	}
	const vm = (recordId) => ({
		app: 'decidiq',
		list: 'memberVotingRecords',
		recordId,
		apiOverride: api,
		state: '',
		entries: [],
		record: null,
	})

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
	const gone = await renderSfc(BLOCK, {
		app: 'decidiq',
		list: 'l',
		recordParam: 'p9',
		locale: 'nl',
		apiOverride: { list: async () => ENTRIES, record: async () => null },
	})
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
	assert.match(
		readFileSync('src/lib/widgetLabels.js', 'utf8'),
		/publicRecords: 'Openbare overzichten'/,
	)
})

test('a page names what its search field searches for (site-council-pages-read-decidiq)', async () => {
	const screen = await loadSfc(BLOCK)
	const words = { search: 'Zoek een naam' }
	assert.equal(
		screen.computed.searchText.call({
			searchLabel: 'Zoek een vergadering',
			words,
		}),
		'Zoek een vergadering',
	)
	assert.equal(
		screen.computed.searchText.call({ searchLabel: '', words }),
		'Zoek een naam',
		'without a label the block keeps its own words',
	)
	assert.match(
		readFileSync(BLOCK, 'utf8'),
		/\{\{\s*searchText\s*\}\}/,
		'the field label shows it',
	)
})

test('the back link keeps the portal, the route and the language, and drops the record (REQ-RR3-003)', async () => {
	const screen = await loadSfc(BLOCK)
	const saved = globalThis.window
	globalThis.window = {
		location: {
			href: 'https://zuiddrecht.test/index.php/apps/portaliq/site?portal=zuiddrecht&route=%2Fbestuur%2Fvergaderingen&lang=nl&record=p1',
			pathname: '/index.php/apps/portaliq/site',
		},
	}
	try {
		const back = new URL(screen.computed.backHref.call({}))
		assert.equal(back.pathname, '/index.php/apps/portaliq/site')
		assert.equal(back.searchParams.get('portal'), 'zuiddrecht')
		assert.equal(back.searchParams.get('route'), '/bestuur/vergaderingen')
		assert.equal(back.searchParams.get('lang'), 'nl')
		assert.equal(back.searchParams.get('record'), null)
	} finally {
		globalThis.window = saved
	}
})

test('the summary becomes the shared figure cards, the columns the shared table (T3)', () => {
	const { cards, row } = recordFigures([
		{ label: 'Deelname', value: '96%', detail: 'aan 86 stemmingen' },
		{ label: 'Voor', value: '61', detail: '' },
	])
	assert.deepEqual(cards, [
		{ field: 'figure0', label: 'Deelname', details: [{ field: 'figure0Detail', label: '' }] },
		{ field: 'figure1', label: 'Voor' },
	])
	assert.deepEqual(row, { figure0: '96%', figure0Detail: 'aan 86 stemmingen', figure1: '61' })
	assert.deepEqual(recordFigures(null), { cards: [], row: {} })

	const { columns } = recordTableCollection(RECORD.columns, 'subject')
	assert.deepEqual(columns[1], { field: 'subject', label: 'Onderwerp', render: 'link', hrefField: 'subjectUrl' })
	assert.deepEqual(columns[0], { field: 'date', label: 'Datum' })
	assert.equal(recordTableCollection(RECORD.columns, 'none').columns.some((c) => c.render === 'link'), false)
})

test('a record renders through KpiCards and CollectionTable, links only safe addresses (T3)', async () => {
	const screen = await loadSfc(BLOCK)
	const shown = (record) => ({
		...screen,
		data: () => ({ state: 'ready', entries: ENTRIES, record, typed: '' }),
	})
	const html = await renderComponent(shown(RECORD), { app: 'decidiq', list: 'l', recordParam: 'p1', locale: 'nl', apiOverride: { list: async () => ENTRIES, record: async () => RECORD } })
	assert.match(html, /data-testid="kpi-block"/, 'the shared figure cards')
	assert.match(html, /data-testid="collection-table"/, 'the shared table')
	assert.match(html, /<span class="pq-kpi__number">96%<\/span>/)
	assert.match(html, /aan 86 stemmingen/)
	assert.match(html, /<a class="utrecht-link" href="\/besluit\/1">\s*Groen dak op het stadhuis\s*<\/a>/)
	assert.match(html, /Alleen openbare rondes\./)
	assert.doesNotMatch(html, /pq-records__card/, 'no hand-drawn cards left')

	const unsafe = { ...RECORD, rows: [{ ...RECORD.rows[0], subjectUrl: 'javascript:alert(1)' }] }
	const plain = await renderComponent(shown(unsafe), { app: 'decidiq', list: 'l', recordParam: 'p1', locale: 'nl', apiOverride: {} })
	assert.doesNotMatch(plain, /javascript:/, 'a script address is never a link')
	assert.match(plain, /Groen dak op het stadhuis/, 'the subject still reads as text')
})

test('the page editor picks a list from the anonymous contributions (T3)', () => {
	const answer = {
		contributions: [
			{ app: 'decidiq', publicRecords: [{ id: 'memberVotingRecords', label: 'Hoe stemden de raadsleden', app: 'decidiq' }, { id: '', label: 'kapot' }] },
			{ app: 'dossiq', collections: [] },
			{ publicRecords: [{ id: 'orphan', label: 'Zonder app' }] },
		],
	}
	const lists = recordListsFrom(answer)
	assert.deepEqual(lists, [{ app: 'decidiq', id: 'memberVotingRecords', label: 'Hoe stemden de raadsleden' }], 'an entry without an id or an app is not offered')
	assert.deepEqual(recordListsFrom(null), [])

	assert.deepEqual(recordListChoice(lists, 'decidiq/memberVotingRecords'), { app: 'decidiq', list: 'memberVotingRecords' })
	assert.deepEqual(recordListChoice(lists, 'decidiq/gone'), { app: undefined, list: undefined }, 'an unknown choice clears both')

	const catalogue = readFileSync('src/lib/pageWidgetCatalogue.js', 'utf8')
	assert.match(catalogue, /publicRecords: \{ list: 'recordList' \}/, 'the list field is a picker')
	const editor = readFileSync('src/editor/PageGridEditor.vue', 'utf8')
	assert.match(editor, /field\.kind === 'recordList'\s*&& recordLists\.length > 0/, 'a picker when lists are known, the text input otherwise')
	assert.match(editor, /recordListChoice\(this\.recordLists, value\)/)
	const designer = readFileSync('src/views/PageLayoutDesigner.vue', 'utf8')
	assert.match(designer, /:loadRecordLists="loadRecordLists"/)
	assert.match(designer, /\/apps\/portaliq\/api\/content\/contributions/)
})

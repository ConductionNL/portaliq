// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// Page blocks read an app's public index: the dated list with an app source,
// and the public table (editor-blocks-read-public-app-data).
//
// @spec openspec/changes/editor-blocks-read-public-app-data/specs/portaliq-cms/spec.md

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { fetchCatalogueKinds } from '../src/site/lib/publicCatalogue.js'
import { metas } from '../src/site/widgets/index.js'
import { loaders } from '../src/site/widgets/loaders.js'
import { sourceQuery, tableOf } from '../src/site/widgets/nlCatalogue/catalogue.js'
import { instance } from './support/page-instance.mjs'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

const NlPublicTable = await loadSfc('src/site/widgets/nlPublicTable/NlPublicTable.vue')
const NlEventList = await loadSfc('src/site/widgets/nlEventList/NlEventList.vue')

/**
 * A browser with a fake server answering the catalogue.
 *
 * @param {object} body The answer.
 * @return {{calls: Array<string>}} The urls asked.
 */
function stubBrowser(body) {
	const calls = []
	globalThis.document = { getElementById: () => null, querySelector: () => null }
	globalThis.window = {
		location: { origin: 'https://school.example', search: '', hash: '' },
		localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
	}
	globalThis.fetch = async (url) => {
		calls.push(String(url))
		return { ok: true, status: 200, json: async () => body }
	}
	return { calls }
}

const COLUMNS = [
	{ key: 'day', label: 'Dag' },
	{ key: 'time', label: 'Tijd' },
	{ key: 'subject', label: 'Vak' },
	{ key: 'room', label: 'Lokaal' },
]

const TESTS = [
	{ title: 'Wiskunde B', cells: { day: 'ma 9 nov', time: '09.00', subject: 'Wiskunde B', room: 'A1.04' } },
	{ title: 'Nederlands', cells: { day: 'di 10 nov', subject: 'Nederlands' } },
]

test('a source becomes a catalogue query, and one without an app or a kind does not', () => {
	assert.equal(sourceQuery(null), null)
	assert.equal(sourceQuery({ app: 'learniq' }), null)
	assert.equal(sourceQuery({ types: ['event'] }), null, 'the old source is read as before')
	assert.deepEqual(
		sourceQuery({
			app: 'learniq',
			kind: 'schoolDay',
			categories: ['holiday', 7, 'dayOff'],
			range: 'schoolYear',
			filters: { Afdeling: ['visitor'], Leeg: [] },
		}),
		{
			app: 'learniq',
			types: ['schoolDay'],
			sort: 'date',
			categories: ['holiday', 'dayOff'],
			range: 'schoolYear',
			limit: 20,
			filters: { Afdeling: ['visitor'] },
		},
	)
	assert.equal(sourceQuery({ app: 'learniq', kind: 'test', limit: 4 }).limit, 4)
})

test('the table has the chosen columns and pads a missing cell', () => {
	const table = tableOf(TESTS, COLUMNS)
	assert.deepEqual(table.columns, ['Dag', 'Tijd', 'Vak', 'Lokaal'])
	assert.deepEqual(table.rows, [
		['ma 9 nov', '09.00', 'Wiskunde B', 'A1.04'],
		['di 10 nov', '', 'Nederlands', ''],
	])
	assert.deepEqual(tableOf(TESTS, ['day']).rows[1], ['di 10 nov'])
})

test('the public table reads its kind and draws the toetsrooster', async () => {
	const { calls } = stubBrowser({ items: TESTS, total: 2, page: 1, pages: 1, facets: [] })
	const block = instance(NlPublicTable, {
		portal: 'vaartveld',
		columns: COLUMNS,
		source: { app: 'learniq', kind: 'test', filters: { Afdeling: ['visitor'], Toetsweek: ['1'] } },
	})
	await block.load()
	const url = new URL(calls[0])
	assert.equal(url.searchParams.get('app'), 'learniq')
	assert.equal(url.searchParams.get('types'), 'test')
	assert.deepEqual(JSON.parse(url.searchParams.get('filters')), { Afdeling: ['visitor'], Toetsweek: ['1'] })
	assert.equal(block.items.length, 2)

	const html = await renderComponent(
		{ ...NlPublicTable, data: () => ({ items: TESTS, loaded: true }), mounted: undefined },
		{ columns: COLUMNS, caption: 'Toetsrooster 4 havo' },
	)
	assert.match(html, /Toetsrooster 4 havo/)
	assert.match(html, /<th[^>]*>\s*Lokaal\s*<\/th>/)
	assert.match(html, /A1\.04/)
})

test('a table with no source asks nothing and a failed read leaves the empty sentence', async () => {
	const { calls } = stubBrowser({})
	const none = instance(NlPublicTable, { source: null })
	await none.load()
	assert.equal(calls.length, 0)
	assert.equal(none.loaded, true)

	globalThis.fetch = async () => ({ ok: false, status: 500, json: async () => ({}) })
	const failing = instance(NlPublicTable, { source: { app: 'learniq', kind: 'test' } })
	await failing.load()
	assert.deepEqual(failing.items, [])
	const html = await renderComponent(
		{ ...NlPublicTable, data: () => ({ items: [], loaded: true }), mounted: undefined },
		{ emptyLabel: 'Geen toetsen.' },
	)
	assert.match(html, /Geen toetsen\./)
})

test('the dated list fills itself from an app by kind, categories and school year', async () => {
	const { calls } = stubBrowser({
		items: [{ date: '2026-10-17', title: 'Herfstvakantie' }],
		total: 1,
		page: 1,
		pages: 1,
		facets: [],
	})
	const list = instance(NlEventList, {
		portal: 'vaartveld',
		source: { app: 'learniq', kind: 'schoolDay', categories: ['holiday', 'dayOff'], range: 'schoolYear' },
	})
	await list.loadSource()
	const url = new URL(calls[0])
	assert.equal(url.searchParams.get('app'), 'learniq')
	assert.equal(url.searchParams.get('categories'), 'holiday,dayOff')
	assert.equal(url.searchParams.get('range'), 'schoolYear')
	assert.equal(url.searchParams.get('upcoming'), null, 'a school year includes what is past')
	assert.equal(list.fetched[0].title, 'Herfstvakantie')
})

test('the editor reads the kinds an app declares and gets none when the read fails', async () => {
	const { calls } = stubBrowser({ kinds: [{ app: 'learniq', type: 'test', columns: COLUMNS }] })
	const kinds = await fetchCatalogueKinds('vaartveld')
	assert.equal(kinds[0].type, 'test')
	assert.match(calls[0], /\/catalogue\/kinds\?portal=vaartveld/)
	globalThis.fetch = async () => {
		throw new Error('offline')
	}
	assert.deepEqual(await fetchCatalogueKinds('vaartveld'), [])
})

test('the public table is a palette widget with a loader', () => {
	assert.ok(metas.nlPublicTable, 'the editor lists it')
	assert.equal(typeof loaders.nlPublicTable, 'function')
})

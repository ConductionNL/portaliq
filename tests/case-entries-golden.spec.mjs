import assert from 'node:assert/strict'
// The case cards a cases block lists, pinned before `entries` is split.
import { test } from 'node:test'
import { golden } from './support/golden.mjs'
import { instance } from './support/page-instance.mjs'
import { loadSfc } from './support/render-sfc.mjs'

const CasesBlock = await loadSfc('src/site/components/mijn/CasesBlock.vue')
const pinned = golden('case-entries')

const NAV = [{
	key: 'dossiq:zaken',
	label: 'Mijn zaken',
	contribution: { app: 'dossiq' },
	page: { id: 'zaken', blocks: [{ type: 'collection', collection: 'mijnZaken' }] },
}]
const COLLECTION = { id: 'mijnZaken', kind: 'cases', columns: [{ key: 'title', label: 'Zaak' }, { key: 'status', label: 'Status' }] }
const ROWS = [
	{ id: 'z-1', title: 'Paspoort', status: 'Open', deadline: '2026-11-20' },
	{ uuid: 'u-2', title: 'Parkeervergunning', status: 'In behandeling' },
	{ '@self': { id: 's-3' }, title: 'Bezwaar' },
	{ title: 'Zonder id' },
]

test('each row becomes a card with its id, link, route and context', () => {
	const ctx = instance(CasesBlock, {
		block: { type: 'cases', collection: 'mijnZaken', yourTurn: ['Open'] },
		collection: COLLECTION,
		rows: ROWS,
		app: 'dossiq',
		nav: NAV,
		locale: 'nl',
		today: new Date('2026-11-10T12:00:00Z'),
	})
	ctx.steps = { 'z-1': { steps: [{ label: 'Ingediend', status: 'done' }, { label: 'Besluit', status: 'current' }] } }
	const entries = ctx.entries
	assert.deepEqual(entries.map((e) => e.id), ['z-1', 'u-2', 's-3', '3'])
	assert.deepEqual(entries[3].link, { app: 'dossiq', collection: 'mijnZaken', id: '' })
	assert.equal(entries[3].route, '')
	pinned.check('entries', entries)
})

test('without a nav entry the route is empty, and without today the card still reads', () => {
	const ctx = instance(CasesBlock, { block: { type: 'cases', collection: 'mijnZaken' }, collection: COLLECTION, rows: ROWS.slice(0, 1), app: 'other', nav: NAV })
	ctx.steps = {}
	assert.equal(ctx.entries[0].route, '')
	assert.equal(typeof ctx.entries[0].card, 'object')
})

test('save the golden', () => pinned.save())

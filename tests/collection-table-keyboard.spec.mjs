#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// collection-table-keyboard.spec.mjs: a keyboard user can open a row of a
// site collection table (portaliq#722, WCAG 2.1 success criterion 2.1.1).
//
// Usage:
//   node --test tests/collection-table-keyboard.spec.mjs
//
// The row selection used to be an onClick on the <tr>: no tabIndex, no key
// handler, no control inside it. Tab walked past every row and Enter did
// nothing, so a resident without a mouse could not open their own case. A
// click handler on a row is invisible to axe, which is why this pins the
// shape directly: every selectable row carries a real <button> that selects
// it, and the row that is open says so.

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { loadSfc, renderSfc } from './support/render-sfc.mjs'

const collection = {
	id: 'mijnZaken',
	columns: [
		{ field: 'identifier', label: 'Zaaknummer' },
		{ field: 'title', label: 'Onderwerp' },
	],
}
const rows = [
	{ id: 'a', identifier: 'Z-001', title: 'Verhuizing' },
	{ id: 'b', identifier: 'Z-002', title: 'Parkeervergunning' },
]

// Held by the site's Vue table (site-reaches-portal-parity slice b,
// REQ-SRP-015).

const VUE_TABLE = 'src/site/components/collections/CollectionTable.vue'
const vueProps = {
	collection,
	objects: rows,
	loading: false,
	t: (key) => key,
	locale: 'en',
}

test('site: every selectable row carries a button that selects that row', async () => {
	const html = await renderSfc(VUE_TABLE, { ...vueProps, selectable: true })
	const buttons =
		html.match(
			/<button type="button" class="[^"]*pq-collection-table__select"[^>]*>[^<]*<\/button>/g,
		) || []
	assert.equal(
		buttons.length,
		rows.length,
		'one real button per row, reachable with Tab',
	)

	const component = await loadSfc(VUE_TABLE)
	const emitted = []
	component.methods.select.call(
		{ $emit: (...args) => emitted.push(args) },
		rows[1],
	)
	assert.deepEqual(emitted, [['select', rows[1]]])
})

test('site: the button is named by the row it opens, the header by the declared label', async () => {
	const html = await renderSfc(VUE_TABLE, { ...vueProps, selectable: true })
	assert.match(html, /data-testid="collection-table-select">Z-001<\/button>/)
	assert.match(
		html,
		/<th scope="col" class="utrecht-table__header-cell">Zaaknummer<\/th>/,
	)
})

test('site: the open row is announced as the current one', async () => {
	const html = await renderSfc(VUE_TABLE, {
		...vueProps,
		selectable: true,
		selectedRow: rows[1],
	})
	const current = html.match(/<tr[^>]*aria-current="true"[^>]*>.*?<\/tr>/)
	assert.ok(current, 'the selected row carries aria-current')
	assert.match(current[0], /Z-002/)
	assert.equal((html.match(/aria-current/g) || []).length, 1)
})

test('site: a table nobody can select from renders no select button', async () => {
	const html = await renderSfc(VUE_TABLE, vueProps)
	assert.doesNotMatch(html, /pq-collection-table__select/)
})

test('site: row buttons follow offers, and the busy row cannot fire twice', async () => {
	const approve = { id: 'approve', type: 'update', label: 'Goedkeuren' }
	const html = await renderSfc(VUE_TABLE, {
		...vueProps,
		rowActions: [approve],
		offers: (action, row) => row.id === 'b',
		busyRow: 'b',
	})
	const buttons =
		html.match(
			/<button[^>]*data-testid="collection-table-action"[^>]*>[^<]*<\/button>/g,
		) || []
	assert.equal(buttons.length, 1, 'only the row the action applies to')
	assert.match(buttons[0], /disabled/)
	assert.match(html, /<tr[^>]*aria-busy="true"[^>]*>.*Z-002/)
	assert.match(
		html,
		/<th scope="col" class="utrecht-table__header-cell">Actions<\/th>/,
	)
})

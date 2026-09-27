#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// collection-table-keyboard.spec.mjs: a keyboard user can open a row of a
// portal table (portaliq#722, WCAG 2.1 success criterion 2.1.1).
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
//
// The component is JSX, so it is compiled here with the same Babel presets
// webpack.portal.js uses and imported from node_modules/.cache, where the
// import of `react` resolves.

import assert from 'node:assert/strict'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'

const require = createRequire(import.meta.url)
const babel = require('@babel/core')
const { renderToStaticMarkup } = require('react-dom/server')

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const SOURCE = join(ROOT, 'src', 'portal', 'components', 'CollectionTable.jsx')
const OUT_DIR = join(ROOT, 'node_modules', '.cache', 'portaliq-tests')
const OUT = join(OUT_DIR, 'CollectionTable.mjs')

const compiled = babel.transformSync(readFileSync(SOURCE, 'utf8'), {
	filename: SOURCE,
	babelrc: false,
	configFile: false,
	presets: [['@babel/preset-react', { runtime: 'automatic' }]],
})
mkdirSync(OUT_DIR, { recursive: true })
writeFileSync(OUT, compiled.code)
const { default: CollectionTable } = await import(pathToFileURL(OUT).href)

const collection = { id: 'mijnZaken', columns: [{ field: 'identifier', label: 'Zaaknummer' }, { field: 'title', label: 'Onderwerp' }] }
const rows = [
	{ id: 'a', identifier: 'Z-001', title: 'Verhuizing' },
	{ id: 'b', identifier: 'Z-002', title: 'Parkeervergunning' },
]

/**
 * Every element in a rendered tree, depth first.
 *
 * @param {object} node - a React element, array or primitive
 * @param {object[]} out - the collected elements
 * @return {object[]} the elements
 */
function elements(node, out = []) {
	if (Array.isArray(node)) {
		node.forEach((child) => elements(child, out))
		return out
	}
	if (node && typeof node === 'object' && node.props) {
		out.push(node)
		elements(node.props.children, out)
	}
	return out
}

test('every selectable row carries a button that selects that row', () => {
	const picked = []
	const tree = CollectionTable({ collection, objects: rows, loading: false, onSelect: (row) => picked.push(row.id) })

	const selectButtons = elements(tree).filter((el) => el.type === 'button' && el.props.className === 'portaliq-row-select')

	assert.equal(selectButtons.length, rows.length, 'one real button per row, reachable with Tab')
	for (const button of selectButtons) {
		assert.equal(button.props.type, 'button')
	}
	// Enter and Space on a <button> fire its click, so this is the keyboard path.
	selectButtons[1].props.onClick({ stopPropagation() {} })
	assert.deepEqual(picked, ['b'])
})

test('the button is named by the row it opens', () => {
	const html = renderToStaticMarkup(CollectionTable({ collection, objects: rows, loading: false, onSelect: () => {} }))

	assert.match(html, /<button type="button" class="portaliq-row-select"[^>]*>Z-001<\/button>/)
})

test('the open row is announced as the current one', () => {
	const html = renderToStaticMarkup(CollectionTable({ collection, objects: rows, loading: false, onSelect: () => {}, selectedRow: rows[1] }))

	const current = html.match(/<tr[^>]*aria-current="true"[^>]*>.*?<\/tr>/)
	assert.ok(current, 'the selected row carries aria-current')
	assert.match(current[0], /Z-002/)
	assert.equal((html.match(/aria-current/g) || []).length, 1)
})

test('a table nobody can select from renders no select button', () => {
	const html = renderToStaticMarkup(CollectionTable({ collection, objects: rows, loading: false }))

	assert.doesNotMatch(html, /portaliq-row-select/)
})

#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// array-cells.spec.mjs: a cell holding a list of values shows one line per
// value on the site (array-cells-one-line-per-item).
// Found in the primary-school live check: learniq's report card `gradeLines`
// ["Rekenen: 7,9", "Taal: 8,3"] read as "Rekenen: 7,9,Taal: 8,3", where the
// Dutch decimal comma and the list comma run together.
//
// Usage:
//   node --test tests/array-cells.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { formatCell } from '../src/site/components/collections/cells.js'
import { renderSfc } from './support/render-sfc.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const VUE_TABLE = 'src/site/components/collections/CollectionTable.vue'

const collection = {
	id: 'parentReportCards',
	columns: [
		{ field: 'periodName', label: 'Period' },
		{ field: 'gradeLines', label: 'Grades' },
	],
}
const rows = [
	{
		id: 'r1',
		periodName: 'Rapport 1',
		gradeLines: ['Rekenen: 7,9', 'Taal: 8,3'],
	},
]
const en = { locale: 'en', t: (key) => key }

test('the site table renders one line per value of a list cell', async () => {
	const html = await renderSfc(VUE_TABLE, {
		collection,
		objects: rows,
		loading: false,
		t: (key) => key,
		locale: 'en',
	})
	assert.doesNotMatch(html, /7,9,Taal/, 'values no longer run together')
	assert.match(html, /Rekenen: 7,9\nTaal: 8,3/)
	assert.match(
		html,
		/<td class="utrecht-table__cell">(<!--\[-->)?Rapport 1(<!--\]-->)?<\/td>/,
		'a plain value is unchanged',
	)
})

test('the site writes a list cell as one line per value', () => {
	assert.equal(
		formatCell(['Rekenen: 7,9', 'Taal: 8,3'], 'text', en),
		'Rekenen: 7,9\nTaal: 8,3',
	)
	assert.equal(formatCell('Rapport 1', 'text', en), 'Rapport 1')
})

test('the site table keeps those line breaks', () => {
	const vue = readFileSync(
		join(
			ROOT,
			'src',
			'site',
			'components',
			'collections',
			'CollectionTable.vue',
		),
		'utf8',
	)
	assert.match(
		vue,
		/\.pq-collection-table \.utrecht-table__cell \{\s*white-space: pre-line;/,
	)
})

#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// array-cells.spec.mjs: a cell holding a list of values shows one line per
// value, in the React portal table and on the site (array-cells-one-line-per-item).
// Found in the primary-school live check: learniq's report card `gradeLines`
// ["Rekenen: 7,9", "Taal: 8,3"] read as "Rekenen: 7,9,Taal: 8,3", where the
// Dutch decimal comma and the list comma run together.
//
// Usage:
//   node --test tests/array-cells.spec.mjs

import assert from 'node:assert/strict'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { formatCell } from '../src/site/components/collections/cells.js'
import { compileLoading, LOADING_MODULE } from './support/compile-loading.mjs'

const require = createRequire(import.meta.url)
const babel = require('@babel/core')
const { renderToStaticMarkup } = require('react-dom/server')

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const SOURCE = join(ROOT, 'src', 'portal', 'components', 'CollectionTable.jsx')
const OUT_DIR = join(ROOT, 'node_modules', '.cache', 'portaliq-tests')
const OUT = join(OUT_DIR, 'CollectionTable.array-cells.mjs')

const compiled = babel.transformSync(readFileSync(SOURCE, 'utf8'), {
	filename: SOURCE,
	babelrc: false,
	configFile: false,
	presets: [['@babel/preset-react', { runtime: 'automatic' }]],
})
mkdirSync(OUT_DIR, { recursive: true })
compileLoading(OUT_DIR)
writeFileSync(OUT, compiled.code.replace("'./Loading.jsx'", `'${LOADING_MODULE}'`))
const { default: CollectionTable } = await import(pathToFileURL(OUT).href)

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

test('the portal table shows one line per value of a list cell', () => {
	const html = renderToStaticMarkup(
		CollectionTable({ collection, objects: rows, loading: false, t: (k) => k }),
	)
	assert.doesNotMatch(html, /7,9,Taal/, 'values no longer run together')
	assert.match(html, /<span class="portaliq-cell-line">Rekenen: 7,9<\/span>/)
	assert.match(html, /<span class="portaliq-cell-line">Taal: 8,3<\/span>/)
	assert.match(html, /<td>Rapport 1<\/td>/, 'a plain value is unchanged')
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

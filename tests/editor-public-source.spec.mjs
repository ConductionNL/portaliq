#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// editor-public-source.spec.mjs: the page editor offers a block that reads an
// app's public index only what that app declares (editor-blocks-read-public-
// app-data, task 4): the app and kind, the kind's categories for a dated list,
// its filters (with the visitor's own) and columns for a table.
//
// Usage:
//   node --test tests/editor-public-source.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	filterValue,
	hasColumn,
	kindFor,
	kindsFrom,
	kindValue,
	VISITOR,
	withCategory,
	withColumn,
	withFilter,
	withKind,
} from '../src/editor/publicSource.js'
import { metaOf as eventListMeta } from '../src/site/widgets/nlEventList/meta.js'
import { metaOf as publicTableMeta } from '../src/site/widgets/nlPublicTable/meta.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const read = (path) => readFileSync(join(ROOT, path), 'utf8')

const TESTS = {
	app: 'learniq',
	type: 'test',
	label: 'Toetsrooster',
	categories: [],
	filters: { Afdeling: ['4 havo', '5 havo', '4 vwo'], Toetsweek: ['1', '2'] },
	columns: [
		{ key: 'day', label: 'Dag' },
		{ key: 'time', label: 'Tijd' },
		{ key: 'subject', label: 'Vak' },
		{ key: 'room', label: 'Lokaal' },
	],
}

const DAYS = {
	app: 'learniq',
	type: 'schoolDay',
	label: 'Schoolkalender',
	categories: ['holiday', 'dayOff', 'activity', 'parentEvening'],
	filters: {},
	columns: [],
}

const KINDS = [TESTS, DAYS]

test('the kinds are read from the endpoint answer and broken ones dropped', () => {
	const kinds = kindsFrom({
		kinds: [
			{ ...TESTS },
			{ app: 'learniq', type: 'schoolDay', categories: ['holiday', 3], filters: { Klas: ['a', 1], Bad: 'x' }, columns: [{ key: 'day', label: 'Dag' }, { label: 'no key' }] },
			{ app: '', type: 'x' },
			{ app: 'learniq' },
			null,
		],
	})
	assert.equal(kinds.length, 2)
	assert.deepEqual(kinds[0], TESTS)
	assert.deepEqual(kinds[1], {
		app: 'learniq',
		type: 'schoolDay',
		label: 'schoolDay',
		categories: ['holiday', '3'],
		filters: { Klas: ['a', '1'], Bad: [] },
		columns: [{ key: 'day', label: 'Dag' }],
	})
	assert.deepEqual(kindsFrom([TESTS]), [TESTS])
	assert.deepEqual(kindsFrom(null), [])
})

test('the select shows the app and kind a source reads', () => {
	assert.equal(kindValue({ app: 'learniq', kind: 'test' }), 'learniq/test')
	assert.equal(kindValue({ types: ['event'] }), '')
	assert.equal(kindValue(undefined), '')
	assert.equal(kindFor(KINDS, { app: 'learniq', kind: 'schoolDay' }), DAYS)
	assert.equal(kindFor(KINDS, { app: 'learniq', kind: 'gone' }), null)
})

test('choosing a kind keeps only what that kind declares', () => {
	const source = {
		app: 'learniq',
		kind: 'test',
		filters: { Afdeling: ['visitor'], Toetsweek: ['7'], Gone: ['1'] },
		categories: ['holiday', 'other'],
		limit: 4,
	}
	assert.deepEqual(withKind(source, KINDS, 'learniq/test'), {
		app: 'learniq',
		kind: 'test',
		limit: 4,
		filters: { Afdeling: ['visitor'] },
	})
	assert.deepEqual(withKind({ ...source, range: 'schoolYear' }, KINDS, 'learniq/schoolDay'), {
		app: 'learniq',
		kind: 'schoolDay',
		limit: 4,
		range: 'schoolYear',
		categories: ['holiday'],
	})
})

test('choosing no app drops an app source and leaves another source alone', () => {
	assert.equal(withKind({ app: 'learniq', kind: 'test' }, KINDS, ''), undefined)
	const catalogue = { types: ['event'] }
	assert.equal(withKind(catalogue, KINDS, ''), catalogue)
	assert.equal(withKind(undefined, KINDS, 'nope/none'), undefined)
})

test('categories switch on and off in the order the kind declares', () => {
	let source = { app: 'learniq', kind: 'schoolDay' }
	source = withCategory(source, DAYS, 'dayOff', true)
	source = withCategory(source, DAYS, 'holiday', true)
	assert.deepEqual(source.categories, ['holiday', 'dayOff'])
	source = withCategory(source, DAYS, 'holiday', false)
	assert.deepEqual(source.categories, ['dayOff'])
	source = withCategory(source, DAYS, 'dayOff', false)
	assert.equal('categories' in source, false)
	assert.deepEqual(withCategory(source, DAYS, 'undeclared', true), { app: 'learniq', kind: 'schoolDay' })
})

test('a filter takes a declared value, the visitor\'s own, or any', () => {
	let source = { app: 'learniq', kind: 'test' }
	source = withFilter(source, TESTS, 'Afdeling', '4 havo')
	source = withFilter(source, TESTS, 'Toetsweek', '1')
	assert.deepEqual(source.filters, { Afdeling: ['4 havo'], Toetsweek: ['1'] })
	assert.equal(filterValue(source, 'Afdeling'), '4 havo')

	source = withFilter(source, TESTS, 'Afdeling', VISITOR)
	assert.equal(filterValue(source, 'Afdeling'), 'visitor')

	source = withFilter(source, TESTS, 'Toetsweek', '9')
	assert.deepEqual(source.filters, { Afdeling: ['visitor'] }, 'an undeclared value is any')
	source = withFilter(source, TESTS, 'Undeclared', '1')
	assert.deepEqual(source.filters, { Afdeling: ['visitor'] })
	source = withFilter(source, TESTS, 'Afdeling', '')
	assert.equal('filters' in source, false)
	assert.equal(filterValue(source, 'Afdeling'), '')
})

test('columns switch on and off in the declared order with the declared labels', () => {
	let columns = withColumn(undefined, TESTS, 'room', true)
	columns = withColumn(columns, TESTS, 'day', true)
	columns = withColumn(columns, TESTS, 'undeclared', true)
	assert.deepEqual(columns, [{ key: 'day', label: 'Dag' }, { key: 'room', label: 'Lokaal' }])
	assert.equal(hasColumn(columns, 'room'), true)
	assert.equal(hasColumn(columns, 'time'), false)
	columns = withColumn(columns, TESTS, 'day', false)
	columns = withColumn(columns, TESTS, 'room', false)
	assert.equal(columns, undefined)
})

test('both blocks ask for the picker on their source, the table on its columns', () => {
	const pickerOf = (meta, name) => meta.fields.find((field) => field.name === name)?.picker
	assert.equal(pickerOf(eventListMeta, 'source'), 'source')
	assert.equal(pickerOf(publicTableMeta, 'source'), 'source')
	assert.equal(pickerOf(publicTableMeta, 'columns'), 'columns')
})

test('the editor draws the picker only once kinds are known, and both hosts load them', () => {
	const editor = read('src/editor/PageGridEditor.vue')
	assert.match(editor, /v-if="field\.picker && catalogueKinds\.length > 0"/)
	assert.match(editor, /this\.catalogueKinds = await this\.loadCatalogueKinds\(\)/)
	assert.match(read('src/views/PageLayoutDesigner.vue'), /:loadCatalogueKinds="loadCatalogueKinds"/)
	assert.match(read('src/views/PageLayoutDesigner.vue'), /\/apps\/portaliq\/api\/content\/catalogue\/kinds/)
	assert.match(read('src/editor/SiteEditMode.vue'), /fetchCatalogueKinds\(this\.portal\)/)
})

test('the picker\'s words are in the Dutch catalogue', () => {
	const nl = JSON.parse(read('l10n/nl.json')).translations
	for (const key of ['App and kind', 'No app', 'Categories', 'Any', 'The visitor\'s own', 'Columns']) {
		assert.ok(nl[key] && nl[key] !== key, key)
	}
})

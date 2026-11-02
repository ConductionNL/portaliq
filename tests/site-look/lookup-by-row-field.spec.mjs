#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// lookup-by-row-field.spec.mjs: a lookup may be keyed on a field of the row,
// and a task may be titled by a sentence with fields (lookup-by-row-field).
//
// Usage:
//   node --test tests/site-look/lookup-by-row-field.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { withLookups } from '../../src/shared/recordPage.js'
import { filledTemplate, taskRows } from '../../src/site/components/mijn/rows.js'

const STORE = {
	parentChildren: {
		objects: [
			{ id: 'vera', givenName: 'Vera' },
			{ '@self': { id: 'sami' }, givenName: 'Sami' },
		],
	},
}
const CHILD = {
	as: 'childName',
	collection: 'parentChildren',
	rowField: 'learnerRef',
	matchField: 'id',
	valueField: 'givenName',
}

test('a row field finds the related record, also by an id in the envelope', () => {
	const rows = withLookups(
		[
			{ id: 'r1', learnerRef: 'sami' },
			{ id: 'r2', learnerRef: 'vera' },
			{ id: 'r3', learnerRef: 'nobody' },
			{ id: 'r4' },
		],
		[CHILD],
		STORE,
		null,
	)
	assert.deepEqual(
		rows.map((row) => row.childName),
		['Sami', 'Vera', '', ''],
	)
})

test('without rowField the lookup still keys on the row id, as before', () => {
	const rows = withLookups(
		[{ id: 'vera' }],
		[
			{
				as: 'name',
				collection: 'parentChildren',
				matchField: 'id',
				valueField: 'givenName',
			},
		],
		STORE,
		null,
	)
	assert.equal(rows[0].name, 'Vera')
})

test('a task reads its sentence, and falls back when a place stays empty', () => {
	const block = {
		titleTemplate: 'Kies een tijd voor het oudergesprek van {childName}',
		titleFields: ['title'],
	}
	const rows = withLookups(
		[
			{ id: 't1', title: 'Oudergesprekken groep 7', learnerRef: 'sami' },
			{ id: 't2', title: 'Oudergesprekken groep 4', learnerRef: 'nobody' },
		],
		[CHILD],
		STORE,
		null,
	)
	assert.deepEqual(
		taskRows(rows, block, {}).map((row) => row.title),
		['Kies een tijd voor het oudergesprek van Sami', 'Oudergesprekken groep 4'],
	)
	assert.equal(filledTemplate('{a} en {b}', { a: 'x' }), '')
})

#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// column-labels.spec.mjs: a collection table's headers read the field's
// label, which the server fills from the schema property's title, before
// the field key as words (collection-column-labels).
//
// Usage:
//   node --test tests/site-look/column-labels.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { deriveColumns } from '../../src/site/components/collections/cells.js'

const ROWS = [
	{ id: 'g1', courseName: 'Engels', value: '6,9', componentId: 'leestoets' },
]

test('without columns, a header reads the field label, not the key', () => {
	const columns = deriveColumns(
		{
			id: 'studentGrades',
			fieldConfigs: {
				courseName: { label: 'Vak' },
				value: { label: 'Cijfer' },
			},
		},
		ROWS,
	)
	assert.deepEqual(
		columns.map((c) => [c.field, c.label]),
		[
			['courseName', 'Vak'],
			['value', 'Cijfer'],
			// No label anywhere: the key as words, never the raw key.
			['componentId', 'Component id'],
		],
	)
})

test('a declared column without a label takes the field label; its own label wins', () => {
	const columns = deriveColumns(
		{
			columns: [
				{ field: 'courseName' },
				{ field: 'value', label: 'Laatste cijfer' },
				{ field: 'componentId', label: '  ' },
			],
			fieldConfigs: {
				courseName: { label: 'Vak' },
				value: { label: 'Cijfer' },
				componentId: { label: 'Toets' },
			},
		},
		ROWS,
	)
	assert.deepEqual(
		columns.map((c) => c.label),
		['Vak', 'Laatste cijfer', 'Toets'],
	)
})

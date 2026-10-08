#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// collection-skip.spec.mjs: a collection block may leave out its first rows
// after its order and before its limit, so a list under a highlight does not
// repeat the highlight's row (collection-skip).
//
// Usage:
//   node --test tests/site-look/collection-skip.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { skipRows, windowRows } from '../../src/shared/listWindow.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
const DAYS = ['2026-10-29', '2026-10-08', '2026-10-15', '2026-10-22'].map(
	(date) => ({
		id: date,
		date,
	}),
)
const SORT = { field: 'date', direction: 'asc' }

test('after the order, the first row is left out, then the limit applies', () => {
	const { rows, more } = windowRows(DAYS, { sort: SORT, skip: 1, limit: 2 })
	assert.deepEqual(
		rows.map((row) => row.date),
		['2026-10-15', '2026-10-22'],
	)
	assert.equal(more, true)
})

test('no skip, or a bad one, leaves every row', () => {
	assert.equal(skipRows(DAYS, {}).length, 4)
	assert.equal(skipRows(DAYS, { skip: 0 }).length, 4)
	assert.equal(skipRows(DAYS, { skip: '1' }).length, 4)
})

test('the whole list, when opened, still leaves the first row out', () => {
	const source = readFileSync(
		join(ROOT, 'src/site/pages/collections/ContributionPage.vue'),
		'utf8',
	)
	assert.match(
		source,
		/rows: skipRows\(sortRows\(this\.rowsOf\(item\), sort\), item\.block\)/,
	)
})

#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// record-page-values-and-picks.spec.mjs: every branch of the record value,
// the kpi row pick and the counted word in src/shared/recordPage.js, pinned
// before they were split into named steps.
//
// Usage:
//   node --test tests/record-page-values-and-picks.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { countedWord, pickRow, recordValue } from '../src/shared/recordPage.js'

test('a record value is its id by default, wherever the envelope keeps it', () => {
	assert.equal(recordValue(null), '')
	assert.equal(recordValue(undefined, 'name'), '')
	assert.equal(recordValue({ id: 7, uuid: 'u' }), '7')
	assert.equal(recordValue({ id: 0, uuid: 'u' }, 'id'), 'u')
	assert.equal(recordValue({ '@self': { id: 's' } }, ''), 's')
	assert.equal(recordValue({ '@self': null }), '')
	assert.equal(recordValue({ '@self': {} }, null), '')
	assert.equal(recordValue('row'), '')
})

test('a record value on another field is that field as text, or empty', () => {
	assert.equal(recordValue({ id: 'r', school: 'De Linde' }, 'school'), 'De Linde')
	assert.equal(recordValue({ id: 'r', count: 0 }, 'count'), '0')
	assert.equal(recordValue({ id: 'r', flag: false }, 'flag'), 'false')
	assert.equal(recordValue({ id: 'r', none: null }, 'none'), '')
	assert.equal(recordValue({ id: 'r' }, 'missing'), '')
})

test('a kpi row is the first without a pick, none without rows', () => {
	assert.equal(pickRow(null), null)
	assert.equal(pickRow([null, undefined]), null)
	const a = { n: 1 }
	assert.equal(pickRow([null, a, { n: 2 }]), a)
	assert.equal(pickRow([a, { n: 2 }], {}), a)
	assert.equal(pickRow([a, { n: 2 }], { field: '' }), a)
})

test('a kpi pick takes the highest by default and the lowest ascending, missing values last', () => {
	const rows = [{ n: 2, k: 'a' }, { n: null, k: 'b' }, { n: 5, k: 'c' }, { k: 'd' }, { n: 2, k: 'e' }, { n: 1, k: 'f' }]
	assert.equal(pickRow(rows, { field: 'n' }).k, 'c')
	assert.equal(pickRow(rows, { field: 'n', direction: 'desc' }).k, 'c')
	assert.equal(pickRow(rows, { field: 'n', direction: 'asc' }).k, 'f')
	assert.equal(pickRow([{ k: 'x' }, { n: null, k: 'y' }, { n: 3, k: 'z' }], { field: 'n', direction: 'asc' }).k, 'z')
	assert.equal(pickRow([{ n: 3, k: 'z' }, { k: 'x' }], { field: 'n' }).k, 'z')
	assert.equal(pickRow([{ n: 'b', k: 1 }, { n: 'a', k: 2 }], { field: 'n', direction: 'asc' }).k, 2)
	const tie = [{ n: 4, k: 'first' }, { n: 4, k: 'second' }]
	assert.equal(pickRow(tie, { field: 'n' }).k, 'first')
	assert.equal(pickRow(tie, { field: 'n', direction: 'asc' }).k, 'first')
	assert.equal(rows[0].k, 'a', 'the rows themselves are not sorted')
})

test('a counted word is a plain string as it is, or the form the figure takes', () => {
	assert.equal(countedWord('leerlingen', 3), 'leerlingen')
	assert.equal(countedWord('', 1), '')
	assert.equal(countedWord(undefined, 1), '')
	assert.equal(countedWord(null, 1), '')
	assert.equal(countedWord({ one: 'leerling' }, 1), '')
	assert.equal(countedWord({ one: 'leerling', other: 3 }, 1), '')
	const word = { one: 'leerling', other: 'leerlingen' }
	assert.equal(countedWord(word, 1), 'leerling')
	assert.equal(countedWord(word, '1'), 'leerling')
	assert.equal(countedWord(word, 1.0), 'leerling')
	assert.equal(countedWord(word, 0), 'leerlingen')
	assert.equal(countedWord(word, 2), 'leerlingen')
	assert.equal(countedWord(word, null), 'leerlingen')
	assert.equal(countedWord(word, ''), 'leerlingen')
	assert.equal(countedWord(word, undefined), 'leerlingen')
	assert.equal(countedWord(word, true), 'leerling')
})

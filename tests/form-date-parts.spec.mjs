#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// form-date-parts.spec.mjs: the day, month and year a date group shows for a
// value (src/site/components/forms/fields.js `dateParts`), on every branch,
// pinned before it was split into named steps.
//
// Usage:
//   node --test tests/form-date-parts.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { dateParts } from '../src/site/components/forms/fields.js'

test('an ISO date splits without leading zeros, a moment by its date', () => {
	assert.deepEqual(dateParts('2026-03-01'), { day: '1', month: '3', year: '2026' })
	assert.deepEqual(dateParts(' 2026-12-31T23:59:00Z '), { day: '31', month: '12', year: '2026' })
	assert.deepEqual(dateParts('0099-01-05'), { day: '5', month: '1', year: '0099' })
})

test('what was typed earlier comes back in its parts, the rest of it in the year', () => {
	assert.deepEqual(dateParts('31-2-2026'), { day: '31', month: '2', year: '2026' })
	assert.deepEqual(dateParts('5-'), { day: '5', month: '', year: '' })
	assert.deepEqual(dateParts('-3-'), { day: '', month: '3', year: '' })
	assert.deepEqual(dateParts('1-2-3-4'), { day: '1', month: '2', year: '3-4' })
	assert.deepEqual(dateParts('vandaag'), { day: 'vandaag', month: '', year: '' })
	assert.deepEqual(dateParts('26-03-01'), { day: '26', month: '03', year: '01' })
})

test('nothing shows empty parts', () => {
	for (const value of ['', '  ', null, undefined]) {
		assert.deepEqual(dateParts(value), { day: '', month: '', year: '' }, String(value))
	}
	assert.deepEqual(dateParts(0), { day: '0', month: '', year: '' })
})

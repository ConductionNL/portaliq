#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// list-window-golden.spec.mjs: how a block's rows are sorted, the span a
// day, week or month range covers around a fixed day, and what a case's
// withdrawal and answer fields show, pinned in a golden file before each was
// split into named steps. Days are built and read in local parts, so the
// golden holds in every time zone.
//
// Usage:
//   node --test tests/list-window-golden.spec.mjs
//   GOLDEN_RECORD=1 node --test tests/list-window-golden.spec.mjs

import { test } from 'node:test'
import { rangeSpan, sortRows } from '../src/shared/listWindow.js'
import { caseFieldNames, withdrawalView } from '../src/shared/withdrawal.js'
import { golden } from './support/golden.mjs'

/**
 * @param {Date} date A day.
 * @return {string} Its local `YYYY-MM-DD`.
 */
function local(date) {
	const pad = (n) => String(n).padStart(2, '0')
	return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`
}

test('sorting, ranges, withdrawals and case fields', () => {
	const file = golden('list-window')
	const rows = [
		{ id: 1, v: '2026-10-03' },
		{ id: 2, v: 5 },
		{ id: 3, v: 'Beta' },
		{ id: 4 },
		{ id: 5, v: '2026-10-01T10:00:00' },
		{ id: 6, v: 'alpha' },
		{ id: 7, v: '' },
		{ id: 8, v: 5 },
		null,
		{ id: 9, v: '2026-13-45 not a date' },
	]
	file.check('sortRows', [
		sortRows(rows, { field: 'v' }),
		sortRows(rows, { field: 'v', direction: 'desc' }),
		sortRows(rows, null),
		sortRows('x', { field: 'v' }),
	].map((list) => list.map((row) => row?.id ?? null)))
	const days = [new Date(2026, 9, 5, 15, 30), new Date(2026, 9, 11, 1), new Date(2026, 11, 31, 23, 59), new Date(2026, 1, 28)]
	file.check('rangeSpan', days.map((day) => ['day', 'week', 'month', 'year', undefined].map((range) => {
		const span = rangeSpan(range, day)
		return span && { from: local(span.from), to: local(span.to) }
	})))
	file.check('withdrawalView', [
		withdrawalView(undefined, {}),
		withdrawalView({ declared: true, open: true, confirmText: 'Zeker?' }, {}),
		withdrawalView({ declared: true, open: true, confirmText: 3 }, null),
		withdrawalView({ declared: true, open: false, reason: 'Te laat' }, {}),
		withdrawalView({ declared: true, reason: 3 }, {}),
		withdrawalView({ declared: 'yes', open: true }, {}),
		withdrawalView({ declared: true, open: true }, { withdrawnAt: '2026-10-01', withdrawalReason: 'Verhuisd' }),
		withdrawalView(null, { withdrawnAt: '2026-10-01', withdrawalReason: 4 }),
		withdrawalView(null, { withdrawnAt: '' }),
	])
	file.check('caseFieldNames', [
		caseFieldNames(null, { fields: { a: {} } }),
		caseFieldNames('x', { fields: { a: {} } }),
		caseFieldNames({}, null),
		caseFieldNames({}, { fields: ['a'] }),
		caseFieldNames({}, { fields: 'a' }),
		caseFieldNames({}, { fields: { a: {}, '@self': {}, _files: {}, withdrawnAt: {}, withdrawalReason: {}, b: {} } }),
	])
	file.save()
})

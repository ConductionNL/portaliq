#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// timetable-golden.spec.mjs: the week's day tiles and one day's rows (the
// numbers, the breaks, the first item that takes place), pinned in a golden
// file before both were split into named steps. Moments are built and read
// in local parts, so the golden holds in every time zone.
//
// Usage:
//   node --test tests/timetable-golden.spec.mjs
//   GOLDEN_RECORD=1 node --test tests/timetable-golden.spec.mjs

import { test } from 'node:test'
import { dayRows, weekDays } from '../src/site/components/mijn/timetable.js'
import { golden } from './support/golden.mjs'

const at = (day, hour, minute = 0) => new Date(2026, 9, day, hour, minute)
const ITEMS = [
	{ key: 'a', start: at(7, 8, 30), end: at(7, 9, 20) },
	{ key: 'b', start: at(7, 9, 20), end: at(7, 10, 10), cancelled: true },
	{ key: 'c', start: at(7, 11, 0), end: at(7, 11, 50), status: 'Ander lokaal' },
	{ key: 'd', start: at(7, 10, 0), end: at(7, 12, 30) },
	{ key: 'e', start: at(7, 13, 0), end: at(7, 13, 50) },
	{ key: 'f', start: at(7, 0), end: at(8, 0), allDay: true },
	{ key: 'g', start: at(10, 10), end: at(10, 11) },
	{ key: 'h', start: at(12, 10), end: at(12, 11) },
]

/**
 * @param {Date} date A moment.
 * @return {string} Its local parts.
 */
function local(date) {
	const pad = (n) => String(n).padStart(2, '0')
	return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`
}

test('the week tiles and the day rows', () => {
	const file = golden('timetable')
	file.check('weekDays', [at(7, 12), at(11, 23), at(5, 0), at(12, 9)].map((today) =>
		weekDays(ITEMS, today).map(({ key, date, count }) => ({ key, date: local(date), count })),
	))
	file.check('weekDays of nothing', weekDays(null, at(7, 12)).map(({ key, count }) => ({ key, count })))
	file.check('dayRows', ['2026-10-07', '2026-10-10', '2026-10-09'].map((key) =>
		dayRows(ITEMS, key).map((row) =>
			row.type === 'break'
				? { ...row, from: local(row.from), to: local(row.to) }
				: { type: row.type, key: row.key, number: row.number, first: row.first },
		),
	))
	file.check('dayRows of nothing', dayRows('x', '2026-10-07'))
	file.check('all cancelled', dayRows([{ key: 'x', start: at(7, 8), end: at(7, 9), cancelled: true }], '2026-10-07').map((row) => row.first))
	file.save()
})

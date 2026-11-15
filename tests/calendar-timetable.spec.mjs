#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// calendar-timetable.spec.mjs: a calendar block drawn as a timetable
// (calendar-timetable-display), as the plain functions it draws from: the
// items with their note, change and cancellation, the week's day tiles, the
// rows of one day with the breaks, and the line that sums the day up.
//
// Usage:
//   node --test tests/calendar-timetable.spec.mjs
//
// @spec openspec/changes/calendar-timetable-display/specs/portal-contribution-contract/spec.md

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { itemsInRange } from '../src/shared/listWindow.js'
import { calendarItems, timetableParts } from '../src/shared/recordPage.js'
import strings from '../src/site/components/mijn/strings.js'
import {
	clockOf,
	dayRows,
	daySummary,
	openingDay,
	weekDays,
} from '../src/site/components/mijn/timetable.js'
import {
	resolveBlocks,
	withStatusLabels,
} from '../src/site/pages/collections/pageBlocks.js'

// Monday 5 October 2026, as the Vaartveld board draws it: seven lessons, two
// breaks, a room change and a cancelled last hour. Local times, so the test
// reads the same in any time zone.
const at = (day, hh, mm) => new Date(2026, 9, day, hh, mm).toISOString()
function lesson(id, day, from, to, vak, extra = {}) {
	return {
		id,
		vak,
		begin: at(day, ...from),
		eind: at(day, ...to),
		lokaal: 'Lokaal 1.12',
		soort: null,
		status: 'scheduled',
		...extra,
	}
}
const ROWS = [
	lesson('l1', 5, [8, 30], [9, 20], 'Nederlands'),
	lesson('l2', 5, [9, 20], [10, 10], 'Wiskunde A'),
	lesson('l3', 5, [10, 30], [11, 20], 'Economie', {
		lokaal: 'Lokaal 0.21',
		soort: 'room-unavailable',
		reden: 'Niet in lokaal 1.08',
	}),
	lesson('l4', 5, [11, 20], [12, 10], 'Engels'),
	lesson('l5', 5, [12, 40], [13, 30], 'Geschiedenis'),
	lesson('l6', 5, [13, 30], [14, 20], 'Mentoruur'),
	lesson('l7', 5, [14, 30], [15, 20], 'Lichamelijke opvoeding', {
		soort: 'teacher-absence',
		status: 'cancelled',
		reden: 'De docent is afwezig',
	}),
	lesson('t1', 6, [8, 30], [9, 20], 'Economie'),
	lesson('x1', 12, [8, 30], [9, 20], 'Volgende week'),
]

const CONTRIBUTION = {
	collections: [
		{
			id: 'lessen',
			fieldConfigs: {
				soort: {
					valueLabels: {
						'room-unavailable': 'Ander lokaal',
						'timetable-change': 'Gewijzigd',
					},
				},
			},
		},
	],
}

const SOURCE = {
	collection: 'lessen',
	startField: 'begin',
	endField: 'eind',
	titleField: 'vak',
	metaField: 'lokaal',
	noteField: 'reden',
	statusField: 'soort',
	cancelledWhen: { field: 'status', in: ['cancelled'] },
}

const MONDAY = new Date(2026, 9, 5, 7, 45)

/**
 * The items as the page computes them: the block resolved against the
 * contribution (which adds the value labels), then narrowed to the range.
 *
 * @param {string} range The block's range.
 * @return {Array<object>}
 */
function itemsFor(range) {
	const [resolved] = resolveBlocks(
		{
			blocks: [
				{ type: 'calendar', display: 'timetable', range, sources: [SOURCE] },
			],
		},
		CONTRIBUTION,
	)
	assert.equal(resolved.kind, 'calendar')
	return itemsInRange(
		calendarItems(resolved.block, { lessen: { objects: ROWS } }, null),
		range,
		MONDAY,
	)
}

test('the source picks up the words of its status field from the collection', () => {
	const source = withStatusLabels(SOURCE, CONTRIBUTION)
	assert.equal(source.statusLabels['room-unavailable'], 'Ander lokaal')
	assert.equal(
		withStatusLabels({ ...SOURCE, statusField: undefined }, CONTRIBUTION)
			.statusLabels,
		undefined,
	)
})

test('an item carries its note, the word of its change and whether it is cancelled', () => {
	const items = itemsFor('day')
	const eco = items.find((item) => item.title === 'Economie')
	assert.equal(eco.note, 'Niet in lokaal 1.08')
	assert.equal(eco.status, 'Ander lokaal')
	assert.equal(eco.cancelled, false)
	const lo = items.find((item) => item.title === 'Lichamelijke opvoeding')
	assert.equal(lo.cancelled, true)
	assert.equal(lo.status, '', 'a value without a word draws no pill')
	assert.deepEqual(timetableParts({ a: 1 }, {}), {
		note: '',
		status: '',
		cancelled: false,
	})
})

test('a day shows its own lessons only, numbered, with a break where there is a gap', () => {
	const rows = dayRows(itemsFor('day'), '2026-10-05')
	const lessons = rows.filter((row) => row.type === 'item')
	assert.equal(lessons.length, 7)
	assert.deepEqual(
		lessons.map((row) => row.number),
		[1, 2, 3, 4, 5, 6, 7],
	)
	const breaks = rows.filter((row) => row.type === 'break')
	assert.deepEqual(
		breaks.map((row) => row.minutes),
		[20, 30, 10],
	)
	assert.equal(
		rows[2].type,
		'break',
		'the 20 minutes after the second lesson sit between them',
	)
	assert.equal(lessons[0].first, true)
	assert.equal(lessons.filter((row) => row.first).length, 1)
})

test('a cancelled first lesson is not the first lesson', () => {
	const items = [
		{
			key: 'a',
			start: new Date(2026, 9, 5, 8, 30),
			end: new Date(2026, 9, 5, 9, 20),
			title: 'A',
			cancelled: true,
		},
		{
			key: 'b',
			start: new Date(2026, 9, 5, 9, 20),
			end: new Date(2026, 9, 5, 10, 10),
			title: 'B',
			cancelled: false,
		},
	]
	const rows = dayRows(items, '2026-10-05')
	assert.deepEqual(
		rows.map((row) => row.first),
		[false, true],
	)
})

test('the summary counts the lessons and the changes, and ends with the last lesson held', () => {
	const sum = daySummary(dayRows(itemsFor('day'), '2026-10-05'))
	assert.equal(sum.count, 7)
	assert.equal(sum.changes, 2)
	assert.equal(
		clockOf(sum.endsAt),
		'14.20',
		'the cancelled 7th hour does not count',
	)
	assert.equal(clockOf(sum.endsAt, 'en'), '14:20')
})

test('a week offers Monday to Friday as tiles and opens on today', () => {
	const items = itemsFor('week')
	assert.ok(
		!items.some((item) => item.title === 'Volgende week'),
		'the range keeps next week out',
	)
	const days = weekDays(items, MONDAY)
	assert.deepEqual(
		days.map((day) => day.key),
		['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09'],
	)
	assert.deepEqual(
		days.map((day) => day.count),
		[7, 1, 0, 0, 0],
	)
	assert.equal(openingDay(days, MONDAY), '2026-10-05')
	assert.equal(dayRows(items, '2026-10-06').length, 1)
})

test('on a Saturday the week opens on its first busy day, and a weekend lesson gets a tile', () => {
	const saturday = new Date(2026, 9, 10, 10, 0)
	const days = weekDays(
		[
			{
				key: 's',
				start: new Date(2026, 9, 10, 9, 0),
				end: new Date(2026, 9, 10, 10, 0),
				title: 'Open dag',
			},
		],
		saturday,
	)
	assert.equal(days.length, 6)
	assert.equal(days[5].key, '2026-10-10')
	assert.equal(openingDay(days, saturday), '2026-10-10')
	assert.equal(openingDay(weekDays([], saturday), saturday), '2026-10-05')
})

test('every timetable word exists in Dutch and English', () => {
	for (const key of [
		'Choose a day',
		'Nothing on the timetable this day.',
		'Break, {minutes} minutes',
		'Cancelled',
		'1 lesson',
		'{count} lessons',
		'1 change',
		'{count} changes',
		'done at {time}',
	]) {
		assert.ok(strings.nl[key], `nl ${key}`)
		assert.ok(strings.en[key], `en ${key}`)
	}
	assert.equal(strings.nl.Cancelled, 'Vervalt')
})

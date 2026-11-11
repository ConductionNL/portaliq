#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// calendar-items.spec.mjs: the items a calendar block makes of its sources'
// rows (titles, ends, meta, all-day, expanded lists, `only`, the same item
// once), pinned in a golden file before the item builder was split into
// named steps. Moments carry no offset and are read back in local parts, so
// the golden holds in every time zone.
//
// Usage:
//   node --test tests/calendar-items.spec.mjs
//   GOLDEN_RECORD=1 node --test tests/calendar-items.spec.mjs

import { test } from 'node:test'
import { calendarItems } from '../src/shared/recordPage.js'
import { golden } from './support/golden.mjs'

/**
 * @param {Date} date A moment.
 * @return {string} Its local date and time.
 */
function local(date) {
	const pad = (n) => String(n).padStart(2, '0')
	return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`
}

/**
 * @param {Array<object>} items Calendar items.
 * @return {Array<object>} The items with their moments in local parts.
 */
function plain(items) {
	return items.map((item) => ({ ...item, start: local(item.start), end: local(item.end) }))
}

const ROWS = {
	lessen: {
		objects: [
			{ id: 'l1', start: '2026-10-08T10:00:00', end: '2026-10-08T11:00:00', vak: 'Wiskunde', lokaal: ' B12 ', status: 'moved', note: ' Neem je boek mee ' },
			{ id: 'l2', start: '2026-10-08T09:00:00', end: '2026-10-08T08:00:00', vak: '  ', status: 'x' },
			{ id: 'l3', start: '2026-10-09', vak: 'Gym', lokaal: 4, status: 2 },
			{ id: 'l4', start: 'never', vak: 'Kapot' },
			{ id: 'l5', vak: 'Geen begin' },
			{ start: '2026-10-07T08:00:00', vak: 'Zonder id', soort: 'uitval' },
			null,
			'text',
		],
	},
	perioden: {
		objects: [
			{ id: 'p1', vakanties: [{ van: '2026-10-19', tot: '2026-10-23', naam: 'Herfstvakantie' }, { van: '2026-12-21', naam: '' }, null, 'x'] },
			{ id: 'p2', vakanties: [{ van: '2026-10-19', tot: '2026-10-23', naam: 'Herfstvakantie' }] },
			{ id: 'p3', vakanties: 'none' },
			{ id: 'p4' },
		],
	},
}

test('the items of every source, in date order, the same item once', () => {
	const file = golden('calendar-items')
	const block = {
		sources: [
			{
				collection: 'lessen',
				startField: 'start',
				endField: 'end',
				titleField: 'vak',
				title: 'Les',
				metaField: 'lokaal',
				kind: 'Les',
				noteField: 'note',
				statusField: 'status',
				statusLabels: { moved: 'Ander lokaal', 2: 'Twee' },
				cancelledWhen: { field: 'soort', in: ['uitval'] },
			},
			{
				collection: 'perioden',
				expand: { field: 'vakanties', startField: 'van', endField: 'tot', titleField: 'naam', title: 'Vakantie' },
				kind: 'Vakantie',
			},
			{ collection: 'lessen', startField: 'start', title: '', only: { field: 'vak', in: ['Gym'] } },
			{ collection: 'lessen', startField: 'start' },
			{ collection: 'leeg', startField: 'start', title: 'x' },
		],
	}
	file.check('all sources', plain(calendarItems(block, ROWS, null)))
	file.check('untitled source with a title', plain(calendarItems({ sources: [{ collection: 'lessen', startField: 'start', title: 'Afspraak' }] }, ROWS, null)))
	file.check('no block', calendarItems(null, ROWS, null))
	file.check('no store', calendarItems(block, null, null))
	file.save()
})

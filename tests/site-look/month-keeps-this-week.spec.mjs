#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// month-keeps-this-week.spec.mjs: "Deze maand" as tiles keeps this week's
// past days and marks today (month-keeps-this-week).
//
// Usage:
//   node --test tests/site-look/month-keeps-this-week.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { renderSfc } from '../support/render-sfc.mjs'

const THURSDAY = new Date(2026, 9, 8, 10, 0)

/**
 * An item on one day.
 *
 * @param {number} day The day of October 2026.
 * @param {string} title The title.
 * @return {object} The item.
 */
function on(day, title) {
	return {
		key: title,
		title,
		start: new Date(2026, 9, day),
		end: new Date(2026, 9, day),
	}
}
const ITEMS = [
	on(1, 'Vorige week'),
	on(5, 'Maandag'),
	on(7, 'Schoolfotograaf'),
	on(8, 'Vandaag'),
	on(9, 'Studiedag'),
]

/**
 * The tiles' titles and the marked one.
 *
 * @param {string} range The block's range.
 * @return {Promise<{titles: Array<string>, html: string}>}
 */
async function tiles(range) {
	const html = await renderSfc('src/site/components/mijn/CalendarTiles.vue', {
		items: ITEMS,
		range,
		today: THURSDAY,
		t: (key) => key,
	})
	return {
		titles: [...html.matchAll(/<strong>([^<]+)<\/strong>/g)].map((m) => m[1]),
		html,
	}
}

test('with the month range a Thursday still shows Monday to Wednesday', async () => {
	const { titles } = await tiles('month')
	assert.deepEqual(titles, ['Maandag', 'Schoolfotograaf', 'Vandaag', 'Studiedag'])
})

test('today is marked', async () => {
	const { html } = await tiles('month')
	assert.match(html, /pq-calendar-tiles__row--today[^>]*aria-current="date"/)
	assert.equal(html.split('aria-current="date"').length - 1, 1)
})

test('without a range the tiles still start today', async () => {
	const { titles } = await tiles('')
	assert.deepEqual(titles, ['Vandaag', 'Studiedag'])
})

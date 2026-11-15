#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// steps-as-bars.spec.mjs: a steps block may draw as a row of bars, one line
// under each, never a date twice (steps-as-bars).
//
// Usage:
//   node --test tests/site-look/steps-as-bars.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { renderSfc } from '../support/render-sfc.mjs'

const STEPS = [
	{
		label: 'Overeenkomst getekend',
		state: 'done',
		date: '2026-08-27',
		description: '27 augustus 2026',
	},
	{ label: 'Werkplan gemaakt', state: 'done', date: '2026-09-09' },
	{ label: 'Tussenbeoordeling', state: 'current', description: '13 oktober 2026' },
	{ label: 'Eindbeoordeling', state: 'upcoming', description: 'januari 2027' },
]

/**
 * Render the steps.
 *
 * @param {string} display `list` or `bars`.
 * @return {Promise<string>} The HTML.
 */
function render(display) {
	return renderSfc('src/site/components/mijn/ProcessSteps.vue', {
		steps: STEPS,
		display,
		tr: (key) =>
			({
				Done: 'Gereed',
				'Current step': 'Huidige stap',
				'Not started': 'Nog niet begonnen',
			})[key] || key,
		locale: 'nl',
		today: new Date(2026, 9, 8),
	})
}

test('as bars: a row of steps, the current one marked', async () => {
	const html = await render('bars')
	assert.match(html, /<ol class="pq-step-bars"/)
	assert.equal(html.split('<li class="').length - 1, 4)
	assert.match(
		html,
		/class="pq-step-bars__step--current pq-step-bars__step" aria-current="step"/,
	)
	assert.doesNotMatch(html, /denhaag-step-marker/)
})

test('as bars: one line under each step, the own words before the date', async () => {
	const html = await render('bars')
	assert.equal(
		html.split('27 augustus').length - 1,
		1,
		'the first date stands once',
	)
	assert.match(html, /pq-step-bars__line">\s*9 september/)
	assert.match(html, /13 oktober 2026/)
})

test('the list stays the Den Haag process steps', async () => {
	const html = await render('list')
	assert.match(html, /denhaag-process-steps pq-process-steps/)
})

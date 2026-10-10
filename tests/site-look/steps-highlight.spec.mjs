#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// steps-highlight.spec.mjs: a steps block may draw the step that matters now
// as a card with its day, its words and a button (steps-highlight).
//
// Usage:
//   node --test tests/site-look/steps-highlight.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { stepMoment } from '../../src/site/components/mijn/stepMoment.js'
import { renderSfc } from '../support/render-sfc.mjs'

const STEPS = [
	{ label: 'Overeenkomst getekend', state: 'done', date: '2026-08-27' },
	{
		label: 'Tussenbeoordeling',
		state: 'current',
		date: '2026-10-13T10:00:00',
		description: 'Ruud Hermans komt naar Bakker Techniek.',
	},
	{ label: 'Eindbeoordeling', state: 'upcoming' },
]

/**
 * Render the block as a highlight.
 *
 * @param {Array<object>} steps The provider's steps.
 * @param {string} route The button's route.
 * @return {Promise<string>} The HTML.
 */
function render(steps, route = '/mijn/learniq/zelfbeoordeling') {
	return renderSfc('src/site/components/mijn/StepsBlock.vue', {
		block: {
			collection: 'placements',
			display: 'highlight',
			eyebrow: 'Volgende stap',
			buttonLabel: 'Zelfbeoordeling afmaken',
			page: 'zelfbeoordeling',
		},
		collection: {
			id: 'placements',
			steps: { provider: 'placementSteps', label: 'Waar sta je?' },
		},
		record: { id: 'p1' },
		initialAnswer: { steps },
		route,
		t: (key) => key,
		locale: 'nl',
	})
}

test('the current step is the card: eyebrow, its day and time, its words, the button', async () => {
	const html = await render(STEPS)
	assert.match(html, /data-testid="mijn-steps-highlight"/)
	assert.match(html, /Volgende stap/)
	assert.match(html, /Tussenbeoordeling op dinsdag 13 oktober, 10\.00 uur/)
	assert.match(html, /Ruud Hermans komt naar Bakker Techniek\./)
	assert.match(
		html,
		/href="\/mijn\/learniq\/zelfbeoordeling"[^>]*>\s*Zelfbeoordeling afmaken/,
	)
	assert.doesNotMatch(html, /Overeenkomst getekend|Eindbeoordeling|Waar sta je\?/)
})

test('without a current step the next one to come is the card; all done shows none', async () => {
	const next = await render([
		STEPS[0],
		{ label: 'Eindbeoordeling', state: 'upcoming' },
	])
	assert.match(next, /pq-steps-highlight__title">\s*Eindbeoordeling\s*</)
	const done = await render([STEPS[0]])
	assert.doesNotMatch(done, /pq-steps-highlight__title/)
	const noRoute = await render(STEPS, '')
	assert.doesNotMatch(noRoute, /mijn-steps-highlight-button/)
})

test('a day without a time reads without one', () => {
	assert.equal(stepMoment('2026-10-13', 'nl'), 'dinsdag 13 oktober')
	assert.equal(
		stepMoment('2026-10-13T10:00:00', 'en'),
		'Tuesday 13 October, 10:00',
	)
	assert.equal(stepMoment('', 'nl'), '')
})

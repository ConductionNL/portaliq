#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// form-unavailable.spec.mjs: what a resident sees in place of a form that
// takes no submissions right now (form-governance-availability-retention-and-routing
// REQ-FGV-001, board FormulierNietBeschikbaar): maintenance with its window
// and the kept draft, a replaced form with a link to its successor, a full
// form with the weekday of its reopening worked out, and the not-yet-open and
// closed texts. loadForm and submitIntake carry the situation to the block.
//
// Usage:
//   node --test tests/form-unavailable.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { longDay, unavailableView } from '../src/site/components/forms/unavailable.js'
import { loadForm, submitIntake } from '../src/site/lib/intakeApi.js'
import { renderSfc } from './support/render-sfc.mjs'

const COMPONENT = 'src/site/components/forms/FormUnavailable.vue'

/**
 * A fetch that answers one canned response.
 *
 * @param {number} status The status.
 * @param {object} body The body.
 * @return {Function}
 */
function answering(status, body) {
	return async () => ({ ok: status >= 200 && status < 300, status, json: async () => body })
}

test('a day is written with the weekday worked out from the date', () => {
	assert.equal(longDay('2027-01-05'), 'dinsdag 5 januari 2027')
	assert.equal(longDay('2026-10-09'), 'vrijdag 9 oktober 2026')
	assert.equal(longDay('2026-10-09', 'en'), 'Friday, 9 October 2026')
	assert.equal(longDay('9-10-2026'), '')
	assert.equal(longDay(null), '')
})

test('the 150 places are taken: the count and the reopening day', () => {
	const view = unavailableView({ situation: 'full', limit: 150, per: 'total', reopensOn: '2027-01-05' })
	assert.equal(view.title, 'Er kunnen nu geen aanvragen meer bij')
	assert.deepEqual(view.lines, [
		'Alle 150 plekken zijn vergeven.',
		'Vanaf dinsdag 5 januari 2027 kunt u weer een aanvraag doen.',
	])
	assert.equal(view.link, null)

	const monthly = unavailableView({ situation: 'full', limit: 500, per: 'month', reopensOn: null })
	assert.deepEqual(monthly.lines, ['Het maximum van 500 aanvragen voor deze maand is bereikt.'])
})

test('maintenance shows its window on one day and says the saved answers stay', () => {
	const view = unavailableView({ situation: 'maintenance', from: '2026-10-09T18:00', until: '2026-10-09T22:00', text: '' })
	assert.equal(view.title, 'Dit formulier is even niet beschikbaar')
	assert.deepEqual(view.lines, [
		'vrijdag 9 oktober 2026 van 18:00 tot 22:00 uur',
		'Was u al bezig? Uw opgeslagen antwoorden blijven bewaard.',
	])

	const overnight = unavailableView({ situation: 'maintenance', from: '2026-10-09T22:00', until: '2026-10-10T06:00', text: 'Wij werken aan een update.' })
	assert.deepEqual(overnight.lines, [
		'Van vrijdag 9 oktober 2026 22:00 tot zaterdag 10 oktober 2026 06:00 uur',
		'Wij werken aan een update.',
		'Was u al bezig? Uw opgeslagen antwoorden blijven bewaard.',
	])
})

test('a replaced form links to its successor on the same portal, never elsewhere', () => {
	const view = unavailableView({ situation: 'replaced', replacedBy: '/aanvragen/woo-verzoek-2027' })
	assert.equal(view.title, 'Dit formulier is niet meer beschikbaar')
	assert.deepEqual(view.link, { href: '/aanvragen/woo-verzoek-2027', label: 'Naar het nieuwe formulier' })
	assert.deepEqual(view.lines, [])

	for (const away of ['//evil.example/x', 'https://evil.example', '/\\evil.example']) {
		const refused = unavailableView({ situation: 'replaced', replacedBy: away })
		assert.equal(refused.link, null, away)
		assert.deepEqual(refused.lines, ['Heeft u een vraag? Neem dan contact met ons op.'])
	}
})

test('not yet open names the opening day; closed gives the contact line', () => {
	assert.deepEqual(unavailableView({ situation: 'notYetOpen', opensOn: '2026-01-01' }).lines, ['Dit formulier opent op donderdag 1 januari 2026.'])
	const closed = unavailableView({ situation: 'closed', closedOn: '2026-12-31' })
	assert.equal(closed.title, 'Dit formulier is niet meer beschikbaar')
	assert.deepEqual(closed.lines, ['Heeft u een vraag? Neem dan contact met ons op.'])
	assert.equal(unavailableView(null), null)
	assert.equal(unavailableView({ situation: 'full', limit: 3 }, 'en').title, 'No more applications can be made right now')
})

test('loadForm answers the unavailable state; a plain no-form stays noForm', async () => {
	const unavailable = { situation: 'full', limit: 150, per: 'total', reopensOn: null }
	const view = await loadForm(
		{ base: '/api', portal: 'gemeente-x', token: '', fetchImpl: answering(200, { kind: 'hosted', resolvesToNoForm: true, reason: 'unavailable', unavailable, fields: [] }) },
		'aanvragen/geveltuin',
	)
	assert.equal(view.state, 'unavailable')
	assert.deepEqual(view.render.unavailable, unavailable)

	const noForm = await loadForm(
		{ base: '/api', portal: 'gemeente-x', token: '', fetchImpl: answering(200, { kind: 'hosted', resolvesToNoForm: true, reason: 'no_published_form_for_audience' }) },
		'aanvragen/geveltuin',
	)
	assert.equal(noForm.state, 'noForm')
})

test('a submit refused because the form closed meanwhile carries the situation', async () => {
	const unavailable = { situation: 'maintenance', from: '2026-10-09T18:00', until: '2026-10-09T22:00', text: '' }
	const outcome = await submitIntake(
		{ base: '/api', portal: '', token: '', fetchImpl: answering(409, { error: 'form_unavailable', unavailable }) },
		{ route: 'woo', answers: { a: 'b' } },
	)
	assert.deepEqual(outcome, { reference: '', confirmationText: '', errors: {}, unavailable })

	await assert.rejects(submitIntake(
		{ base: '/api', portal: '', token: '', fetchImpl: answering(409, { error: 'conflict' }) },
		{ route: 'woo', answers: {} },
	))
})

test('site: the component renders the title, the lines and the successor link', async () => {
	const html = await renderSfc(COMPONENT, { unavailable: { situation: 'replaced', replacedBy: '/aanvragen/woo-2027' }, locale: 'nl' })
	assert.match(html, /data-situation="replaced"/)
	assert.match(html, /Dit formulier is niet meer beschikbaar/)
	assert.match(html, /href="\/aanvragen\/woo-2027"/)

	const full = await renderSfc(COMPONENT, { unavailable: { situation: 'full', limit: 150, per: 'total', reopensOn: '2027-01-05' }, locale: 'nl' })
	assert.match(full, /Vanaf dinsdag 5 januari 2027 kunt u weer een aanvraag doen\./)
	assert.doesNotMatch(full, /<a /)

	assert.equal((await renderSfc(COMPONENT, { unavailable: null })).replace(/<!--.*?-->/g, ''), '')
})

#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// form-availability.spec.mjs: a form that takes no submissions shows the
// matching FormulierNietBeschikbaar situation instead of the form
// (form-governance-availability-retention-and-routing REQ-FGV-001).
//
// Usage:
//   node --test tests/form-availability.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { unavailableView } from '../src/site/components/forms/unavailable.js'
import { loadForm, submitIntake } from '../src/site/lib/intakeApi.js'
import { renderSfc } from './support/render-sfc.mjs'

function answering (status, body) {
	return async () => ({ status, ok: status >= 200 && status < 300, json: async () => body })
}

// The board writes "maandag 5 januari 2027" and "donderdag 9 oktober 2026"; both days
// fall on another weekday, and the page writes the real one.
test('the 150 places are taken', () => {
	const view = unavailableView({ situation: 'limitReached', count: 150, per: 'total', reopensOn: '2027-01-05' }, 'de subsidie geveltuinen 2026', 'nl')
	assert.equal(view.heading, 'Er kunnen nu geen aanvragen meer bij')
	assert.deepEqual(view.lines, [
		'Voor de subsidie geveltuinen 2026 zijn alle 150 plekken vergeven.',
		'Vanaf dinsdag 5 januari 2027 kunt u weer een aanvraag doen.',
	])
})

test('maintenance shows the window and keeps the draft', () => {
	const view = unavailableView({ situation: 'maintenance', from: '2026-10-09T18:00', until: '2026-10-09T22:00', text: '' }, 'Woo-verzoek indienen', 'nl')
	assert.equal(view.heading, 'Dit formulier is even niet beschikbaar')
	assert.deepEqual(view.lines, [
		'Wij werken aan het formulier Woo-verzoek indienen. Dat doen wij op vrijdag 9 oktober 2026 van 18.00 tot 22.00 uur.',
		'Was u al bezig? Uw opgeslagen antwoorden blijven bewaard. Na 22.00 uur gaat u verder waar u was.',
	])
	const own = unavailableView({ situation: 'maintenance', from: '2026-10-09T18:00', until: '2026-10-09T22:00', text: 'Wij zetten de nieuwe versie klaar.' }, '', 'nl')
	assert.equal(own.lines[0], 'Wij zetten de nieuwe versie klaar.')
})

test('a replaced form points to its successor, and only on the same portal', () => {
	const view = unavailableView({ situation: 'replaced', replacedBy: '/aanvragen/woo-verzoek-2027' }, '', 'nl')
	assert.equal(view.heading, 'Dit formulier is niet meer beschikbaar')
	assert.deepEqual(view.link, { href: '/aanvragen/woo-verzoek-2027', label: 'Naar het nieuwe formulier' })
	assert.equal(unavailableView({ situation: 'replaced', replacedBy: '//elders.example' }, '', 'nl').link, null)
})

test('before its first day the form says when it opens', () => {
	const view = unavailableView({ situation: 'notYetOpen', opensOn: '2027-01-04' }, '', 'nl')
	assert.deepEqual(view.lines, ['Dit formulier opent op maandag 4 januari 2027.'])
})

test('the situation is in English for an English page', () => {
	const view = unavailableView({ situation: 'limitReached', count: 150, per: 'total', reopensOn: '2027-01-05' }, '', 'en')
	assert.equal(view.heading, 'No more applications can be added now')
	assert.equal(view.lines[1], 'From Tuesday 5 January 2027 you can apply again.')
})

test('the page renders the situation with the link and the portal contact', async () => {
	const html = await renderSfc('src/site/components/forms/FormUnavailable.vue', {
		situation: { situation: 'replaced', replacedBy: '/aanvragen/woo-verzoek-2027' },
		help: { phone: '14 0123', email: 'info@zuiddrecht.nl' },
		locale: 'nl',
	})
	assert.match(html, /data-situation="replaced"/)
	assert.match(html, /href="\/aanvragen\/woo-verzoek-2027"/)
	assert.match(html, /Liever bellen of mailen\?/)
	assert.match(html, /14 0123/)
})

test('loading a closed form gives the unavailable state', async () => {
	const body = { unavailable: { situation: 'closed' }, formName: 'Geveltuin' }
	const view = await loadForm('https://portal.example/api', 'aanvragen/geveltuin', '', '', answering(200, body))
	assert.equal(view.state, 'unavailable')
	assert.deepEqual(view.render.unavailable, { situation: 'closed' })
})

test('a submit refused because the form closed keeps the answers and names the situation', async () => {
	const outcome = await submitIntake('https://portal.example/api', 'aanvragen/geveltuin', { naam: 'R. Mulder' }, '', '', answering(409, { error: 'form_unavailable', unavailable: { situation: 'closed' } }))
	assert.equal(outcome.reference, '')
	assert.deepEqual(outcome.unavailable, { situation: 'closed' })
})

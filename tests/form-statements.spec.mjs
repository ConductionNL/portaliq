#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// form-statements.spec.mjs: a form can open with an introduction, ends its
// review with the statements it asks, and confirms with the binding's own
// words (form-statements-intro-and-confirmation-mail REQ-FCI-001 to -003).
//
// Usage:
//   node --test tests/form-statements.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	confirmationView,
	fillBody,
	introView,
	missingStatements,
} from '../src/site/components/forms/confirmation.js'
import { submitIntake } from '../src/site/lib/intakeApi.js'
import { loadSfc, renderSfc } from './support/render-sfc.mjs'

const BODY = 'Uw verzoek is ontvangen onder kenmerk {reference}. U krijgt uiterlijk {deadline} een besluit.'

test('fillBody fills the reference and the date, and drops a sentence with an empty value', () => {
	assert.equal(
		fillBody(BODY, { reference: 'WOO-1', deadline: '1 november' }),
		'Uw verzoek is ontvangen onder kenmerk WOO-1. U krijgt uiterlijk 1 november een besluit.',
	)
	assert.equal(fillBody(BODY, { reference: 'WOO-1', deadline: '' }), 'Uw verzoek is ontvangen onder kenmerk WOO-1.')
	assert.equal(fillBody('', { reference: 'x' }), '')
})

test('confirmationView takes the binding, falls back to the form text and the default title', () => {
	const own = confirmationView(
		{ title: 'Bedankt', body: BODY, next: [{ title: 'Beoordeling', text: 'Wij kijken mee.' }, {}] },
		'Fallback.',
		{ reference: 'WOO-1' },
		'Standaard',
	)
	assert.equal(own.title, 'Bedankt')
	assert.equal(own.next.length, 1)

	const fallback = confirmationView(null, 'Bedankt, u hoort van ons.', { reference: 'WOO-1' }, 'Standaard')
	assert.deepEqual(fallback, { title: 'Standaard', body: 'Bedankt, u hoort van ons.', next: [] })
})

test('a required statement not ticked, or without text, is missing', () => {
	const asked = [
		{ key: 'truth', required: true, text: 'Klopt.' },
		{ key: 'privacy', required: true, text: 'Akkoord.' },
		{ key: 'extra', required: false, text: 'Mag.' },
		{ key: 'blind', required: true, text: '' },
	]
	assert.deepEqual(missingStatements(asked, ['truth']), ['privacy', 'blind'])
	assert.deepEqual(missingStatements(asked, ['truth', 'privacy', 'blind']), ['blind'])
	assert.deepEqual(missingStatements([], []), [])
})

test('introView is null without content, so the form opens at step 1', () => {
	assert.equal(introView(null), null)
	assert.equal(introView({}), null)
	assert.equal(introView({ blocks: [{}] }), null)
	assert.equal(introView({ lead: 'Vraag informatie.' }).lead, 'Vraag informatie.')
})

test('site: the introduction shows Voordat u begint, its blocks and Start', async () => {
	const html = await renderSfc('src/site/components/forms/FormIntro.vue', {
		formName: 'Woo-verzoek indienen',
		locale: 'nl',
		intro: { lead: 'Vraag de gemeente om informatie.', blocks: [{ title: 'Wat hebt u nodig', text: 'Uw DigiD.', items: ['Een e-mailadres'] }] },
	})
	assert.match(html, /Voordat u begint/)
	assert.match(html, /Wat hebt u nodig/)
	assert.match(html, /Een e-mailadres/)
	assert.match(html, /data-testid="form-intro-start"[^>]*>\s*Start/)
})

test('site: the statements are unchecked, a statement without text cannot be ticked', async () => {
	const html = await renderSfc('src/site/components/forms/StatementsBlock.vue', {
		locale: 'nl',
		statements: [
			{ key: 'truth', required: true, text: 'Mijn antwoorden kloppen.' },
			{ key: 'privacy', required: true, text: '' },
		],
		modelValue: [],
		errors: { privacy: 'Vink deze verklaring aan voordat u verstuurt.' },
	})
	assert.match(html, /Verklaringen/)
	assert.match(html, /<input type="checkbox" aria-invalid="false" data-testid="statement-truth">/)
	assert.match(html, /<input type="checkbox" disabled aria-invalid="true" data-testid="statement-privacy">/)
	assert.match(html, /Mijn antwoorden kloppen\./)
	assert.match(html, /data-testid="statement-error-privacy"/)
})

test('submitIntake sends the ticked statements and returns the confirmation and the mail address', async () => {
	let sent = null
	const fetchImpl = async (url, init) => {
		sent = JSON.parse(init.body)
		return {
			ok: true,
			status: 200,
			json: async () => ({ reference: 'WOO-1', confirmation: { title: 'Bedankt' }, mailedTo: 'sanne@example.nl' }),
		}
	}
	const outcome = await submitIntake('/api', 'woo', { a: 'b' }, '', '', fetchImpl, ['truth', 'privacy'])

	assert.deepEqual(sent.statements, ['truth', 'privacy'])
	assert.equal(outcome.mailedTo, 'sanne@example.nl')
	assert.equal(outcome.confirmation.title, 'Bedankt')

	await submitIntake('/api', 'woo', {}, '', '', fetchImpl)
	assert.equal('statements' in sent, false, 'nothing is sent for a form that asks none')
})

test('site: sending is stopped on a required statement that is not ticked', async () => {
	globalThis.window = globalThis.window || {
		localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
		sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
		location: { hash: '', pathname: '/', search: '' },
	}
	const screen = await loadSfc('src/site/components/IntakeFormBlock.vue')
	const vm = {
		fields: [],
		render: { statements: [{ key: 'privacy', required: true, text: 'Akkoord.' }] },
		accepted: [],
		errors: {},
		statementErrors: {},
		submitting: false,
		sendFailed: false,
		text: { statementRequired: 'Vink deze verklaring aan voordat u verstuurt.' },
		isShownField: () => true,
		checkFields: () => ({}),
	}
	await screen.methods.submit.call(vm)

	assert.deepEqual(vm.statementErrors, { privacy: 'Vink deze verklaring aan voordat u verstuurt.' })
	assert.equal(vm.submitting, false, 'nothing was sent')
	assert.equal(typeof screen.methods.print, 'function')
})

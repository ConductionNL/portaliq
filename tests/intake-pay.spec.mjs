#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// intake-pay.spec.mjs: intake-pay-on-submit T06 on the public site. The form
// block names a declared fee before the sign-in and after sending, its
// "Pay now" asks the pay route and goes to the checkout it answers, and the
// status block reads the payment state the server read from integriq's
// record, never the return address.
//
// Usage:
//   node --test tests/intake-pay.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	feeText,
	lookUpStatus,
	payIntake,
	paymentView,
} from '../src/site/lib/intakeApi.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const BASE = '/apps/portaliq/portal/api'
const FEE = { amount: '45.00', currency: 'EUR', description: 'Parkeervergunning' }

/**
 * A fetch double that records each call and answers from a queue.
 *
 * @param {Array<{status: number, body: object}>} answers The answers, in order.
 * @return {{fetchImpl: Function, calls: Array<object>}}
 */
function recorder(answers) {
	const calls = []
	const queue = [...answers]
	const fetchImpl = async (url, init = {}) => {
		calls.push({ url: String(url), init })
		const next = queue.shift() || { status: 200, body: {} }
		return {
			ok: next.status >= 200 && next.status < 300,
			status: next.status,
			json: async () => next.body,
		}
	}
	return { fetchImpl, calls }
}

test('the fee reads as an amount in euros, with the sentences around it', () => {
	const text = feeText(FEE)
	assert.equal(text.costs, 'Deze aanvraag kost €\u00a045,00.')
	assert.equal(text.pay, '€\u00a045,00 nu betalen')
	assert.equal(
		text.signIn,
		'Log in om deze aanvraag te versturen. Er zijn leges van €\u00a045,00.',
	)
	assert.equal(feeText(null), null)
	assert.equal(feeText({ amount: 'x' }), null)
})

test('pay sends only the reference and the portal, with the bearer, and answers the checkout', async () => {
	const { fetchImpl, calls } = recorder([
		{ status: 200, body: { checkoutUrl: 'https://www.mollie.com/checkout/abc' } },
		{ status: 502, body: { error: 'payment_unavailable' } },
	])

	const paid = await payIntake(BASE, 'AANVRAAG-7', 'gemeente-x', 'tok-1', fetchImpl)
	assert.deepEqual(paid, { checkoutUrl: 'https://www.mollie.com/checkout/abc' })
	assert.equal(calls[0].url, `${BASE}/intake/pay`)
	assert.equal(calls[0].init.method, 'POST')
	assert.equal(calls[0].init.headers.Authorization, 'Bearer tok-1')
	assert.deepEqual(JSON.parse(calls[0].init.body), {
		reference: 'AANVRAAG-7',
		portal: 'gemeente-x',
	})

	await assert.rejects(
		payIntake(BASE, 'AANVRAAG-7', 'gemeente-x', 'tok-1', fetchImpl),
		/502/,
	)
})

test('the payment state reads as the server read it, with Pay now only where it can be paid', () => {
	assert.deepEqual(paymentView({ payment: { state: 'paid' } }), {
		sentence: 'Betaald',
		canPay: false,
	})
	assert.deepEqual(paymentView({ payment: { state: 'open' } }), {
		sentence: 'Nog niet betaald',
		canPay: true,
	})
	assert.deepEqual(paymentView({ payment: { state: 'failed' } }), {
		sentence: 'De betaling is mislukt',
		canPay: true,
	})
	assert.deepEqual(paymentView({ payment: { state: 'unknown' } }), {
		sentence: 'We kunnen de betaling nog niet tonen',
		canPay: false,
	})
	assert.equal(paymentView({ state: 'queued' }), null)
	assert.equal(paymentView(null), null)
})

test('the status lookup sends no payment state of its own: a ?status=paid stays in the address bar', async () => {
	const { fetchImpl, calls } = recorder([
		{ status: 200, body: { reference: 'A-1', state: 'queued', payment: { state: 'failed' } } },
	])
	const status = await lookUpStatus(BASE, 'A-1', 'gemeente-x', fetchImpl)
	assert.equal(paymentView(status).sentence, 'De betaling is mislukt')
	assert.doesNotMatch(calls[0].url, /status=/)
})

test('the form and status blocks use the pay route and leave for the checkout in the top window', () => {
	const form = readFileSync(join(ROOT, 'src/site/components/IntakeFormBlock.vue'), 'utf8')
	const status = readFileSync(join(ROOT, 'src/site/components/IntakeStatusBlock.vue'), 'utf8')
	for (const source of [form, status]) {
		assert.match(source, /payIntake\(/)
		assert.match(source, /window\.top\.location\.assign\(/)
		assert.match(source, /U kunt nu niet betalen\. Probeer het later opnieuw\./)
	}
	assert.match(form, /feeText\(/)
	assert.match(form, /data-testid="intake-form-pay"/)
	assert.match(form, /data-testid="intake-form-sign-in-fee"/)
	assert.match(status, /paymentView\(/)
	assert.match(status, /data-testid="intake-status-payment"/)
})

test('no pay sentence carries an em-dash', () => {
	const sentences = [
		...Object.values(feeText(FEE)),
		...['paid', 'open', 'failed', 'unknown'].map(
			(state) => paymentView({ payment: { state } }).sentence,
		),
	]
	for (const sentence of sentences) {
		assert.doesNotMatch(sentence, /—/)
	}
})

#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// intake-pay.spec.mjs: a request with a fee shows what it costs, offers
// "Pay now" after submitting, sends the resident to the checkout the portal
// allowed, and on return says what the payment record says
// (intake-pay-on-submit REQ-IPS-001, -002, -005).
//
// Usage:
//   node --test tests/intake-pay.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { feeAmount, paymentView, returnedReference } from '../src/site/components/forms/payment.js'
import { payIntake } from '../src/site/lib/intakeApi.js'

globalThis.window = {
	location: { hash: '', pathname: '/', search: '' },
	localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
	sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
	history: { replaceState() {} },
}
const FEE = { amount: '45.00', currency: 'EUR', description: 'Parkeren', payAction: 'create-payment' }
const { inState, t } = await import('./support/page-instance.mjs')
const { loadSfc, renderComponent } = await import('./support/render-sfc.mjs')
const BLOCK = await loadSfc('src/site/components/IntakeFormBlock.vue')

function render (data) {
  return renderComponent(inState(BLOCK, { reference: 'AANVRAAG-1', ...data }), { t, route: 'parkeren', portal: 'gemeente-x' })
}

test('feeAmount writes the declared amount and nothing else', () => {
	assert.match(feeAmount(FEE, 'nl'), /^€\s?45,00$/)
	assert.match(feeAmount(FEE, 'en'), /^€45\.00$/)
	assert.equal(feeAmount(null, 'nl'), '')
	assert.equal(feeAmount({ amount: '0', currency: 'EUR' }, 'nl'), '')
	assert.equal(feeAmount({ amount: '12.00', currency: 'euro' }, 'nl'), '')
})

test('the payment record decides the words, and an unknown state offers no second payment', () => {
	assert.deepEqual(paymentView({ state: 'paid' }), { key: 'paid', again: false })
	assert.deepEqual(paymentView({ state: 'unpaid' }), { key: 'unpaid', again: true })
	assert.deepEqual(paymentView({ state: 'failed' }), { key: 'paymentFailed', again: true })
	assert.deepEqual(paymentView({ state: 'unknown' }), { key: 'paymentUnknown', again: false })
	assert.equal(paymentView(null), null)
	assert.equal(paymentView({}), null)
})

test('only a plain reference is read from the address, and a status in it is ignored', () => {
	assert.equal(returnedReference('?route=/parkeren&reference=AANVRAAG-1&status=paid'), 'AANVRAAG-1')
	assert.equal(returnedReference('?reference=<script>'), '')
	assert.equal(returnedReference(''), '')
})

test('payIntake sends the reference and the bearer, never an amount, and fails closed', async () => {
	let seen = null
	const ok = async (url, init) => {
		seen = { url, init }
		return { ok: true, status: 200, json: async () => ({ checkoutUrl: 'https://www.mollie.com/x' }) }
	}
	const paid = await payIntake('/api', 'AANVRAAG-1', 'gemeente-x', 'tok', ok)

	assert.equal(paid.ok, true)
	assert.equal(paid.checkoutUrl, 'https://www.mollie.com/x')
	assert.match(seen.url, /\/api\/intake\/pay$/)
	assert.deepEqual(JSON.parse(seen.init.body), { reference: 'AANVRAAG-1', portal: 'gemeente-x' })
	assert.equal(seen.init.headers.Authorization, 'Bearer tok')

	assert.equal((await payIntake('/api', 'R', '', 't', async () => ({ ok: false, status: 502, json: async () => ({ error: 'payment_unavailable' }) }))).ok, false)
	assert.equal((await payIntake('/api', 'R', '', 't', async () => ({ ok: true, status: 200, json: async () => ({}) }))).ok, false)
	assert.equal((await payIntake('/api', 'R', '', 't', async () => { throw new Error('down') })).ok, false)
})

test('site: after sending, a fee shows the amount and "Pay now"', async () => {
	const html = await render({ state: 'done', render: { fee: FEE } })

	assert.match(html, /data-testid="intake-form-fee"[^>]*>\s*Deze aanvraag kost €\s?45,00\./)
	assert.match(html, /data-testid="intake-form-pay"[^>]*>\s*Betaal €\s?45,00 nu/)
})

test('site: a free request shows no fee and no button', async () => {
	const html = await render({ state: 'done', render: { fee: null } })

	assert.doesNotMatch(html, /intake-form-fee|intake-form-pay/)
})

test('site: on return the page says Paid and offers no second payment; Not paid and Failed offer Pay now again', async () => {
	const paid = await render({ state: 'done', render: { fee: FEE }, payment: { state: 'paid' } })
	assert.match(paid, /data-testid="intake-form-payment"[^>]*>\s*Betaald/)
	assert.doesNotMatch(paid, /intake-form-pay"/)

	for (const [state, words] of [['unpaid', 'Nog niet betaald'], ['failed', 'De betaling is mislukt']]) {
		const html = await render({ state: 'done', render: { fee: FEE }, payment: { state } })
		assert.match(html, new RegExp(`data-testid="intake-form-payment"[^>]*>\\s*${words}`))
		assert.match(html, /data-testid="intake-form-pay"/)
	}

	const unknown = await render({ state: 'done', render: { fee: FEE }, payment: { state: 'unknown' } })
	assert.match(unknown, /Wij kunnen de betaling nu nog niet tonen/)
	assert.doesNotMatch(unknown, /intake-form-pay"/)
})

test('site: a visitor without a session is asked to sign in with the fee named, before any question', async () => {
	const html = await render({ state: 'signIn', render: { fee: FEE } })

	assert.match(html, /data-testid="intake-form-sign-in"[^>]*>\s*Log in om deze aanvraag in te dienen\. Er hoort een bedrag van €\s?45,00 bij\./)
	assert.doesNotMatch(html, /intake-field-/)
})

test('site: pay failing shows the sentence and stays on the page; success navigates the top window', async () => {
	globalThis.document = { getElementById: () => null, documentElement: { lang: 'nl' } }
	globalThis.window.fetch = async () => ({ ok: false, status: 502, json: async () => ({ error: 'payment_unavailable' }) })
	const vm = { reference: 'AANVRAAG-1', portal: 'gemeente-x', paying: false, payFailed: false }
	await BLOCK.methods.pay.call(vm)
	assert.equal(vm.payFailed, true)
	assert.equal(vm.paying, false)

	let went = ''
	globalThis.window.top = { location: { assign: (url) => { went = url } } }
	globalThis.window.fetch = async () => ({ ok: true, status: 200, json: async () => ({ checkoutUrl: 'https://www.mollie.com/x' }) })
	await BLOCK.methods.pay.call(vm)
	assert.equal(went, 'https://www.mollie.com/x')
	assert.equal(vm.payFailed, false)
})

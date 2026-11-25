#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// intake-send-failed.spec.mjs: a submit the destination did not answer
// (REQ-FGV-003, form-governance T05/T06 on the direct submit). The form keeps
// the answers, says "Het versturen is niet gelukt", shows the error code the
// server logged the failure under, and offers "Opnieuw proberen" with the same
// Idempotency-Key, so a retry after a lost answer creates no second case.
//
// Usage:
//   node --test tests/intake-send-failed.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { DUTCH } from '../src/site/components/forms/fields.js'
import { submitIntake } from '../src/site/lib/intakeApi.js'
import { loadSfc } from './support/render-sfc.mjs'

globalThis.window = globalThis.window || {
	location: { hash: '', pathname: '/', search: '' },
	localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
	sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
	history: { replaceState() {} },
}

/**
 * A fetch that answers each call with the next response, and keeps the calls.
 *
 * @param {Array<{status: number, body: object}>} answers The responses, in order.
 * @return {{fetchImpl: Function, calls: Array<{url: string, init: object}>}} The fetch and its calls.
 */
function answering(answers) {
	const calls = []
	const fetchImpl = async (url, init) => {
		calls.push({ url: String(url), init })
		const answer = answers[Math.min(calls.length - 1, answers.length - 1)]
		return { ok: answer.status < 300, status: answer.status, json: async () => answer.body }
	}
	return { fetchImpl, calls }
}

test('submitIntake throws with the error code of a submit the destination did not answer', async () => {
	const { fetchImpl } = answering([{ status: 503, body: { error: 'try_again_later', code: 'OF-1A2B' } }])
	await assert.rejects(
		submitIntake({ base: '/api', portal: 'zuid', token: '', fetchImpl }, { route: 'woo', answers: {} }),
		(error) => error.status === 503 && error.code === 'OF-1A2B',
	)

	const bare = answering([{ status: 503, body: { error: 'try_again_later' } }])
	await assert.rejects(
		submitIntake({ base: '/api', portal: '', token: '', fetchImpl: bare.fetchImpl }, { route: 'woo', answers: {} }),
		(error) => error.status === 503 && error.code === '',
	)
})

test('submitIntake sends the key of the filled-in form as Idempotency-Key, never in the body', async () => {
	const { fetchImpl, calls } = answering([{ status: 201, body: { reference: 'WOO-1' } }])
	const key = 'f3a1c2d4-0000-4000-8000-000000000001'
	await submitIntake({ base: '/api', portal: '', token: '', fetchImpl }, { route: 'woo', answers: {}, idempotencyKey: key })

	assert.equal(calls[0].init.headers['Idempotency-Key'], key)
	assert.equal('idempotencyKey' in JSON.parse(calls[0].init.body), false)

	const none = answering([{ status: 201, body: { reference: 'WOO-2' } }])
	await submitIntake({ base: '/api', portal: '', token: '', fetchImpl: none.fetchImpl }, { route: 'woo', answers: {} })
	assert.equal('Idempotency-Key' in none.calls[0].init.headers, false)
})

/**
 * The block's state for a submit, with every method of the block bound.
 *
 * @param {object} screen The component options.
 * @param {Function} fetchImpl The fetch the connection uses.
 * @return {object} The instance.
 */
function block(screen, fetchImpl) {
	globalThis.window.fetch = fetchImpl
	const vm = {
		portal: '',
		bindingRoute: 'woo',
		fields: [],
		values: { onderwerp: 'milieu' },
		errors: {},
		statementErrors: {},
		accepted: [],
		verifiedEmails: {},
		onBehalf: null,
		render: { statements: [] },
		text: DUTCH,
		identity: {},
		state: 'form',
		submitting: false,
		sendFailed: false,
		failureCode: '',
		submissionKey: '',
		formError: '',
		$refs: {},
		$nextTick: (callback) => callback(),
		isShownField: () => true,
		checkFields: () => ({}),
	}
	for (const [name, method] of Object.entries(screen.methods)) {
		if (!Object.hasOwn(vm, name)) {
			vm[name] = method.bind(vm)
		}
	}
	return vm
}

test('site: a failed send keeps the answers and its code, and the retry sends the same key', async () => {
	const screen = await loadSfc('src/site/components/IntakeFormBlock.vue')
	const { fetchImpl, calls } = answering([
		{ status: 503, body: { error: 'try_again_later', code: 'OF-9F3C' } },
		{ status: 201, body: { reference: 'WOO-7', confirmationText: 'Ontvangen.' } },
	])
	const vm = block(screen, fetchImpl)

	await screen.methods.submit.call(vm)
	assert.equal(vm.sendFailed, true)
	assert.equal(vm.failureCode, 'OF-9F3C')
	assert.equal(vm.state, 'form', 'the form stays, with its answers')
	assert.deepEqual(vm.values, { onderwerp: 'milieu' })
	const firstKey = calls[0].init.headers['Idempotency-Key']
	assert.ok(firstKey, 'the first send carries a key')

	await screen.methods.submit.call(vm)
	assert.equal(calls[1].init.headers['Idempotency-Key'], firstKey, 'the retry sends the same key')
	assert.equal(vm.sendFailed, false)
	assert.equal(vm.failureCode, '')
	assert.equal(vm.state, 'done')
	assert.equal(vm.reference, 'WOO-7')
	assert.equal(vm.submissionKey, '', 'a sent form starts a new key')
})

test('site: a send that never reached the server shows no code', async () => {
	const screen = await loadSfc('src/site/components/IntakeFormBlock.vue')
	const vm = block(screen, async () => {
		throw new Error('offline')
	})

	await screen.methods.submit.call(vm)
	assert.equal(vm.sendFailed, true)
	assert.equal(vm.failureCode, '')
})

test('the form words for a failed send name the kept answers, the code and the retry', () => {
	assert.equal(DUTCH.sendFailed, 'Het versturen is niet gelukt. Uw antwoorden staan nog in dit formulier.')
	assert.equal(DUTCH.errorCode.split('{code}').join('OF-9F3C'), 'Foutcode: OF-9F3C. Noem deze code als u ons belt.')
	assert.equal(DUTCH.retry, 'Opnieuw proberen')
})

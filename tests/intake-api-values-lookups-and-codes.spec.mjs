#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// intake-api-values-lookups-and-codes.spec.mjs: what the site's intake client
// (src/site/lib/intakeApi.js) starts each field with, what an address lookup
// and a step decision answer, and when a mailed code is sent and when a typed
// one proves the address; pinned with allowed and refused outcomes before
// these were split into named steps.
//
// Usage:
//   node --test tests/intake-api-values-lookups-and-codes.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	checkEmailCode,
	decideStep,
	initialValues,
	lookupAddress,
	requestEmailCode,
} from '../src/site/lib/intakeApi.js'

/**
 * A connection whose fetch answers from `respond` and notes each call.
 *
 * @param {(url: string) => object|Error} respond The answer, or an Error to throw.
 * @return {{conn: object, calls: Array<object>}} The connection and the calls.
 */
function connection(respond) {
	const calls = []
	const fetchImpl = async (url, init = {}) => {
		calls.push({ url, method: init.method || 'GET', body: init.body ? JSON.parse(init.body) : undefined })
		const answer = respond(url)
		if (answer instanceof Error) {
			throw answer
		}
		return answer
	}
	return { conn: { base: '/apps/portaliq/portal/api', portal: 'demo', token: 'tok', fetchImpl }, calls }
}

function response (status, body) {
  return { ok: status >= 200 && status < 300, status, json: async () => {
	if (body instanceof Error) {
		throw body
	}
	return body
} }
}

test('a field starts with the visitor\'s own detail, else its type\'s empty value, else its preset', () => {
	const fields = [
		{ name: 'naam', type: 'text', preset: 'x' },
		{ name: 'leeftijd', type: 'number' },
		{ name: 'leden', type: 'familyMembers', preset: 'y' },
		{ name: 'adres', type: 'addressNL' },
		{ name: 'keuze', type: 'select', preset: 0 },
		{ name: 'leeg', type: 'text', preset: null },
		{ name: 'nul', type: 'text' },
		{ name: 'waar', type: 'checkbox' },
		{ name: 'toString', type: 'text' },
	]
	const values = initialValues(fields, { naam: 'Jan', leeftijd: 0, leden: '', keuze: null, nul: 0, waar: false })
	assert.deepEqual(values.naam, 'Jan')
	assert.deepEqual(values.leeftijd, '0')
	assert.deepEqual(values.leden, [])
	assert.equal(typeof values.adres, 'object')
	assert.equal(values.adres.postcode, '')
	assert.equal(values.keuze, '0')
	assert.equal(values.leeg, '')
	assert.equal(values.nul, '0')
	assert.equal(values.waar, 'false')
	assert.equal(values.toString, '')
	assert.notEqual(initialValues(fields, {}).leden, initialValues(fields, {}).leden, 'a fresh empty value each time')
})

test('an address lookup answers street and town, else null', async () => {
	const found = connection(() => response(200, { street: 'Dorpsweg', town: 'Utrecht', extra: 1 }))
	assert.deepEqual(await lookupAddress(found.conn, { postcode: '3511AB', number: '1', letter: 'a', addition: '' }), { street: 'Dorpsweg', town: 'Utrecht' })
	assert.equal(found.calls[0].method, 'GET')
	assert.match(found.calls[0].url, /\/intake\/address\?/)
	assert.match(found.calls[0].url, /postcode=3511AB/)
	assert.match(found.calls[0].url, /number=1/)
	assert.match(found.calls[0].url, /letter=a/)
	for (const answer of [response(200, { street: 'Dorpsweg' }), response(200, { town: 'U' }), response(200, null), response(404, { street: 'a', town: 'b' }), response(200, new Error('x')), new Error('offline')]) {
		assert.equal(await lookupAddress(connection(() => answer).conn, { postcode: 'p', number: '1' }), null)
	}
	assert.deepEqual(await lookupAddress(connection(() => response(200, { street: 1, town: 2 })).conn, {}), { street: '1', town: '2' })
})

test('a step decision answers the outcome, its field and the next step, and a refusal throws', async () => {
	const ok = connection(() => response(200, { outcome: 'toegekend', output: 'besluit', nextStep: 's2' }))
	assert.deepEqual(await decideStep(ok.conn, { route: '/aanvraag', step: 's1', answers: { a: 1 } }), { outcome: 'toegekend', output: 'besluit', nextStep: 's2' })
	assert.deepEqual(ok.calls[0].body, { route: '/aanvraag', step: 's1', answers: { a: 1 }, portal: 'demo' })
	const bare = connection(() => response(200, {}))
	assert.deepEqual(await decideStep(bare.conn, { route: '/a', step: 's' }), { outcome: '', output: '', nextStep: '' })
	assert.deepEqual(bare.calls[0].body.answers, {})
	assert.deepEqual(await decideStep(connection(() => response(200, new Error('x'))).conn, { route: '/a', step: 's' }), { outcome: '', output: '', nextStep: '' })
	await assert.rejects(decideStep(connection(() => response(503, {})).conn, { route: '/a', step: 's' }))
	await assert.rejects(decideStep(connection(() => new Error('offline')).conn, { route: '/a', step: 's' }), /offline/)
})

test('a code request is sent, or refused with its reason and when to ask again', async () => {
	const sent = connection(() => response(200, { resendAfter: 30 }))
	assert.deepEqual(await requestEmailCode(sent.conn, { route: '/a', email: 'j@x.nl' }), { ok: true, error: '', resendAfter: 30 })
	assert.deepEqual(sent.calls[0].body, { route: '/a', email: 'j@x.nl', portal: 'demo' })
	assert.match(sent.calls[0].url, /\/intake\/email-code$/)
	assert.deepEqual(await requestEmailCode(connection(() => response(200, {})).conn, { route: '/a', email: 'e' }), { ok: true, error: '', resendAfter: 60 })
	assert.deepEqual(await requestEmailCode(connection(() => response(429, { error: 'too_soon', resendAfter: 12 })).conn, { route: '/a', email: 'e' }), { ok: false, error: 'too_soon', resendAfter: 12 })
	assert.deepEqual(await requestEmailCode(connection(() => response(400, new Error('x'))).conn, { route: '/a', email: 'e' }), { ok: false, error: 'failed', resendAfter: 60 })
	assert.deepEqual(await requestEmailCode(connection(() => response(400, { resendAfter: 'x' })).conn, { route: '/a', email: 'e' }), { ok: false, error: 'failed', resendAfter: 60 })
	assert.deepEqual(await requestEmailCode(connection(() => new Error('offline')).conn, { route: '/a', email: 'e' }), { ok: false, error: 'failed', resendAfter: 60 })
})

test('only a right code with a proof proves the address', async () => {
	const right = connection(() => response(200, { proof: 'p-1' }))
	assert.deepEqual(await checkEmailCode(right.conn, { route: '/a', email: 'e', code: '123456' }), { ok: true, error: '', proof: 'p-1' })
	assert.deepEqual(right.calls[0].body, { route: '/a', email: 'e', code: '123456', portal: 'demo' })
	assert.match(right.calls[0].url, /\/intake\/email-code\/check$/)
	for (const [answer, error] of [
		[response(200, { proof: '' }), 'failed'],
		[response(200, { proof: 7 }), 'failed'],
		[response(200, {}), 'failed'],
		[response(422, { proof: 'p', error: 'wrong_code' }), 'wrong_code'],
		[response(200, new Error('x')), 'failed'],
		[new Error('offline'), 'failed'],
	]) {
		assert.deepEqual(await checkEmailCode(connection(() => answer).conn, { route: '/a', email: 'e', code: '1' }), { ok: false, error, proof: '' })
	}
})

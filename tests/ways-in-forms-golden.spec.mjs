#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// ways-in-forms-golden.spec.mjs: what the register and reference-link forms
// of the ways in send (the challenge's proof, the honeypot, the contact
// invitation) and say (registered, awaiting approval, refused), pinned
// before both were split into named steps.
//
// Usage:
//   node --test tests/ways-in-forms-golden.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { loadSfc } from './support/render-sfc.mjs'

globalThis.window ??= { sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} }, localStorage: { getItem: () => null } }
const WAYS = await loadSfc('src/site/components/WaysIn.vue')
const t = (text) => `t:${text}`

/**
 * The ways-in block with a fake identity api.
 *
 * @param {object} answers The answer per api call.
 * @param {object} state What differs.
 * @return {object} The block, with `calls`.
 */
function ways(answers, state = {}) {
	const calls = []
	const api = new Proxy({}, {
		get: (target, name) => async (arg) => {
			calls.push([name, arg])
			return answers[name]
		},
	})
	return { ...WAYS.data.call({}), t, api, calls, ways: { referenceCaseTypes: [] }, ...state }
}

test('registering: no challenge, a challenge with a honeypot and a proof of work, a contact invitation', async () => {
	const plain = ways({ challenge: null, registerAccount: { ok: true, data: { awaiting: false } } })
	plain.registration.email = 'a@x.nl'
	plain.registration.name = 'A'
	await WAYS.methods.register.call(plain)
	assert.deepEqual(plain.calls, [
		['challenge', 'registration'],
		['registerAccount', { email: 'a@x.nl', displayName: 'A', challenge: {}, solution: '', contactInvitation: '', honeypot: null }],
	])
	assert.equal(plain.registration.busy, false)
	assert.equal(plain.registration.outcome.role, 'status')

	const guarded = ways({ challenge: { nonce: 'n', difficulty: 1, honeypotField: 'website' }, registerAccount: { ok: true, data: { awaiting: true } } }, { contactInvitation: 'inv' })
	guarded.registration.trap = 'bot'
	await WAYS.methods.register.call(guarded)
	const sent = guarded.calls[1][1]
	assert.equal(sent.honeypot.field, 'website')
	assert.equal(sent.honeypot.value, 'bot')
	assert.equal(sent.contactInvitation, 'inv')
	assert.equal(guarded.registration.outcome.role, 'status')
	assert.equal(typeof sent.solution, 'string')
	assert.notEqual(sent.solution, '')

	const refused = ways({ challenge: {}, registerAccount: { ok: false, error: 'domain_not_allowed' } })
	await WAYS.methods.register.call(refused)
	assert.equal(refused.registration.outcome.role, 'alert')
	assert.match(refused.registration.outcome.text, /^t:/)
})

test('a reference link: no case type, sent, refused', async () => {
	const none = ways({})
	await WAYS.methods.requestLink.call(none)
	assert.deepEqual(none.calls, [])
	assert.equal(none.reference.busy, false)

	const types = [{ register: 'r', schema: 's', caseType: 'melding' }, { register: 'r2', schema: 's2', caseType: 'klacht' }]
	const sent = ways({ requestReferenceLink: { ok: true } }, { ways: { referenceCaseTypes: types } })
	sent.reference.choice = 1
	sent.reference.caseReference = 'Z-1'
	sent.reference.email = 'a@x.nl'
	await WAYS.methods.requestLink.call(sent)
	assert.deepEqual(sent.calls, [['requestReferenceLink', { register: 'r2', schema: 's2', caseType: 'klacht', caseReference: 'Z-1', email: 'a@x.nl' }]])
	assert.deepEqual(sent.reference.outcome, { role: 'status', text: 't:If the case number and the e-mail address belong together, we sent a link to that address. It works once.' })
	assert.equal(sent.reference.busy, false)

	const refused = ways({ requestReferenceLink: { ok: false, error: 'too_many' } }, { ways: { referenceCaseTypes: types } })
	await WAYS.methods.requestLink.call(refused)
	assert.equal(refused.reference.outcome.role, 'alert')
})

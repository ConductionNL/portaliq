#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// area-api-calls.spec.mjs: the request each contact and plan action sends and
// the outcome it reads back, pinned before the call tables and the outcome
// were split into named steps.
//
// Usage:
//   node --test tests/area-api-calls.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { contactsApi, plansApi } from '../src/shared/areaApi.js'

/**
 * A portal api double that answers every request with one answer.
 *
 * @param {object} answer The answer.
 * @return {{api: object, calls: Array}}
 */
function recording(answer) {
	const calls = []
	return { calls, api: { request: async (...args) => (calls.push(args), answer) } }
}

test('each contact action sends its request', async () => {
	const { api, calls } = recording({ ok: true, status: 200, json: { error: 'x' } })
	const contacts = contactsApi(api)
	const args = { id: 'a/b', email: 'e@x', message: 'm', accept: 'yes', token: 't' }
	for (const action of ['invite', 'accept', 'respond', 'resend', 'withdraw', 'remove']) {
		assert.deepEqual(await contacts.contactAction(action, args), { ok: true, status: 200, error: 'x' })
	}
	await contacts.contactAction('respond', { id: 'c', accept: true })
	await contacts.contactAction('resend')
	assert.deepEqual(calls, [
		['POST', '/contacts/invite', { email: 'e@x', message: 'm' }],
		['POST', '/contacts/accept-invitation', { token: 't' }],
		['POST', '/contacts/a%2Fb/respond', { accept: false }],
		['POST', '/contacts/a%2Fb/resend', {}],
		['POST', '/contacts/a%2Fb/withdraw', {}],
		['DELETE', '/contacts/a%2Fb', null],
		['POST', '/contacts/c/respond', { accept: true }],
		['POST', '/contacts//resend', {}],
	])
	assert.deepEqual(await contacts.contactAction('unknown', args), { ok: false, status: 0, error: 'unknown' })
	assert.equal(calls.length, 8)
	const refused = recording({ ok: false, status: 403, json: null })
	assert.deepEqual(await contactsApi(refused.api).contactAction('remove', { id: 'z' }), { ok: false, status: 403, error: '' })
})

test('each plan action sends its request', async () => {
	const { api, calls } = recording({ ok: true, status: 201, json: { id: 9, error: '' } })
	const plans = plansApi(api)
	const data = { goal: 'g' }
	const args = { id: 'p 1', ref: 'r/2', actionId: 'a 3', data }
	for (const action of ['start', 'update', 'delete', 'addParticipants', 'removeParticipant', 'addAction', 'updateAction']) {
		assert.deepEqual(await plans.planAction(action, args), { ok: true, status: 201, error: '', id: '9' })
	}
	for (const action of ['start', 'update', 'addParticipants', 'addAction', 'updateAction', 'removeParticipant']) {
		await plans.planAction(action)
	}
	assert.deepEqual(calls, [
		['POST', '/plans', data],
		['PATCH', '/plans/p%201', data],
		['DELETE', '/plans/p%201', null],
		['POST', '/plans/p%201/participants', data],
		['DELETE', '/plans/p%201/participants/r%2F2', null],
		['POST', '/plans/p%201/actions', data],
		['PATCH', '/plans/p%201/actions/a%203', data],
		['POST', '/plans', {}],
		['PATCH', '/plans/', {}],
		['POST', '/plans//participants', {}],
		['POST', '/plans//actions', {}],
		['PATCH', '/plans//actions/', {}],
		['DELETE', '/plans//participants/', null],
	])
	assert.deepEqual(await plans.planAction('archive', args), { ok: false, status: 0, error: 'unknown', id: '' })
	const refused = recording({ ok: false, status: 422, json: { error: 'invalid' } })
	assert.deepEqual(await plansApi(refused.api).planAction('start', args), { ok: false, status: 422, error: 'invalid', id: '' })
})

test('a plan file is sent with its previous version when there is one', async () => {
	const { api, calls } = recording({ ok: true, status: 200, json: {} })
	const plans = plansApi(api)
	assert.deepEqual(await plans.addPlanFile('p 1', 'FILE'), { ok: true, status: 200, error: '' })
	await plans.addPlanFile('p 1', 'FILE', 'v1')
	assert.deepEqual(calls.map((call) => [call[0], call[1], [...call[2].entries()]]), [
		['POST', '/plans/p%201/files', [['file', 'FILE']]],
		['POST', '/plans/p%201/files', [['file', 'FILE'], ['previousVersion', 'v1']]],
	])
})

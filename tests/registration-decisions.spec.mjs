#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// registration-decisions.spec.mjs: what staff are told when they approve or
// refuse a registration or save who may register: allowed, forbidden,
// already decided and failed, with the exact request each sends, pinned
// before the decision was split into named steps.
//
// Usage:
//   node --test tests/registration-decisions.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createRegistrationSettings } from '../src/lib/registrationSettings.js'

/**
 * The settings over a transport where every call answers or rejects.
 *
 * @param {object|null} failure The rejection, or null to succeed.
 * @return {{settings: object, calls: Array<object>}} The settings and the calls.
 */
function settingsWith(failure) {
	const calls = []
	const answer = (verb) => async (address, body) => {
		calls.push({ verb, address, body })
		if (failure) {
			throw failure
		}
		return { data: { id: 'p1', '@self': { id: 'p1' }, name: 'Portaal' } }
	}
	const settings = createRegistrationSettings({
		get: answer('get'),
		put: answer('put'),
		post: answer('post'),
		url: (path, params = {}) => Object.entries(params).reduce((out, [key, value]) => out.replace(`{${key}}`, value), path),
		translate: (text) => `t:${text}`,
	})
	return { settings, calls }
}

test('an allowed approval and refusal post the account route and say so', async () => {
	const { settings, calls } = settingsWith(null)
	assert.deepEqual(await settings.approve({ subjectRef: 'sub-1' }), { ok: true, message: 't:The registration is approved.' })
	assert.deepEqual(await settings.refuse({ subjectRef: 'sub-1' }, '  spam  '), { ok: true, message: 't:The registration is refused.' })
	assert.deepEqual(await settings.approve(null), { ok: true, message: 't:The registration is approved.' })
	assert.deepEqual(calls, [
		{ verb: 'post', address: '/apps/portaliq/api/accounts/sub-1/approve', body: {} },
		{ verb: 'post', address: '/apps/portaliq/api/accounts/sub-1/refuse', body: { reason: 'spam' } },
		{ verb: 'post', address: '/apps/portaliq/api/accounts//approve', body: {} },
	])
})

test('a refusal without a reason is not sent', async () => {
	const { settings, calls } = settingsWith(null)
	assert.deepEqual(await settings.refuse({ subjectRef: 'sub-1' }, '   '), { ok: false, message: 't:Give a reason.' })
	assert.deepEqual(await settings.refuse({ subjectRef: 'sub-1' }, null), { ok: false, message: 't:Give a reason.' })
	assert.equal(calls.length, 0)
})

test('a forbidden, an already decided and a failed decision each say why', async () => {
	const forbidden = settingsWith({ response: { status: 403, data: { error: 'not_pending' } } })
	assert.deepEqual(await forbidden.settings.approve({ subjectRef: 's' }), {
		ok: false,
		message: 't:You may not decide on registrations. Ask an administrator for this right.',
	})
	const decided = settingsWith({ response: { status: 409, data: { error: 'not_pending' } } })
	assert.deepEqual(await decided.settings.refuse({ subjectRef: 's' }, 'x'), {
		ok: false,
		message: 't:Someone already decided on this registration.',
	})
	for (const failure of [{ response: { status: 500 } }, new Error('offline'), { response: { status: 401 } }]) {
		const failed = settingsWith(failure)
		assert.deepEqual(await failed.settings.approve({ subjectRef: 's' }), {
			ok: false,
			message: 't:The decision could not be saved. Try again.',
		})
	}
})

test('saving who may register: allowed, forbidden and failed', async () => {
	const { settings, calls } = settingsWith(null)
	assert.deepEqual(await settings.save('p1', { policy: 'approval', allowedDomains: ['school.nl'] }), { ok: true, message: 't:Your choices are saved.' })
	assert.deepEqual(calls[0], { verb: 'get', address: '/apps/openregister/api/objects/portaliq/portal/p1', body: undefined })
	assert.deepEqual(calls[1], {
		verb: 'put',
		address: '/apps/openregister/api/objects/portaliq/portal/p1',
		body: { id: 'p1', name: 'Portaal', authentication: { registration: { policy: 'approval', allowedDomains: ['school.nl'] } } },
	})
	const forbidden = settingsWith({ response: { status: 403 } })
	assert.deepEqual(await forbidden.settings.save('p1', {}), { ok: false, message: 't:Only an administrator can change who may register.' })
	const failed = settingsWith({ response: { status: 500 } })
	assert.deepEqual(await failed.settings.save('p1', {}), { ok: false, message: 't:The registration settings could not be saved. Try again.' })
})

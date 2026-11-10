#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// staff-issue-golden.spec.mjs: what issuing an account at the desk sends and
// answers: issued, a second account refused, a missing organisation or
// identity refused before anything is sent, and a refused provision, pinned
// before the issue was split into named steps.
//
// Usage:
//   node --test tests/staff-issue-golden.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createStaffAccountActions } from '../src/lib/staffAccountActions.js'

/**
 * The desk's calls over a server that answers once.
 *
 * @param {object} answer `{data}` or `{reject}`.
 * @return {{actions: object, calls: Array<object>}} The calls and the actions.
 */
function desk(answer) {
	const calls = []
	const actions = createStaffAccountActions({
		post: async (url, body) => {
			calls.push({ url, body })
			if (answer.reject) {
				throw answer.reject
			}
			return { data: answer.data }
		},
		generateUrl: (path) => `/nc${path}`,
		translate: (text) => `t:${text}`,
		formatDate: (iso) => iso,
	})
	return { actions, calls }
}

test('an issued account: the trimmed body with its defaults, and the issued sentence', async () => {
	const { actions, calls } = desk({ data: { isNew: true } })
	assert.deepEqual(
		await actions.issue({ organisation: ' gemeente-x ', identityType: 'bsn', identityRef: ' 999 ', email: ' a@x.nl ', verifiedEmail: true, displayName: ' A ' }),
		{ ok: true, message: 't:The account is issued. It becomes active when its owner signs in for the first time.' },
	)
	assert.deepEqual(calls, [{
		url: '/nc/apps/portaliq/api/accounts/provision',
		body: { audience: 'client', organisation: 'gemeente-x', identityType: 'bsn', identityRef: '999', email: 'a@x.nl', verifiedEmail: true, displayName: 'A' },
	}])
	const staff = desk({ data: {} })
	await staff.actions.issue({ audience: 'staff', organisation: 'o', email: 'b@x.nl', verifiedEmail: 'yes' })
	assert.deepEqual(staff.calls[0].body, { audience: 'staff', organisation: 'o', identityType: '', identityRef: '', email: 'b@x.nl', verifiedEmail: false, displayName: '' })
	const noData = desk({ data: null })
	assert.equal((await noData.actions.issue({ organisation: 'o', identityRef: 'r' })).ok, true)
})

test('refused: a second account, no organisation, no identity, and a refused provision', async () => {
	const twice = desk({ data: { isNew: false } })
	assert.deepEqual(await twice.actions.issue({ organisation: 'o', identityRef: 'r' }), {
		ok: false,
		message: 't:An account for this identity already exists, so no second account was made.',
	})
	const none = desk({ data: {} })
	assert.deepEqual(await none.actions.issue({ organisation: ' ', identityRef: 'r' }), { ok: false, message: 't:Give the organisation the account belongs to.' })
	assert.deepEqual(await none.actions.issue({ organisation: 'o', identityRef: ' ', email: '' }), { ok: false, message: 't:Give an identity reference or an e-mail address.' })
	assert.deepEqual(await none.actions.issue(null), { ok: false, message: 't:Give the organisation the account belongs to.' })
	assert.equal(none.calls.length, 0, 'nothing is sent')
	const refused = desk({ reject: { response: { status: 403, data: {} } } })
	const answer = await refused.actions.issue({ organisation: 'o', email: 'a@x.nl' })
	assert.equal(answer.ok, false)
	assert.match(answer.message, /^t:/)
	assert.equal(Object.keys(answer).length, 2)
})

test('an invitation: the trimmed body, the expiry in the sentence, refused before sending, refused by the server', async () => {
	const sent = desk({ data: { expiresAt: '2026-12-01' } })
	assert.deepEqual(await sent.actions.invite({ email: ' a@x.nl ', organisation: ' o ' }), {
		ok: true,
		message: 't:Invitation sent to {email}. It is valid until {date}.',
	})
	assert.deepEqual(sent.calls, [{ url: '/nc/apps/portaliq/api/invitations', body: { email: 'a@x.nl', organisation: 'o', audience: 'client' } }])
	const staff = desk({ data: {} })
	await staff.actions.invite({ email: 'b@x.nl', organisation: 'o', audience: 'staff' })
	assert.equal(staff.calls[0].body.audience, 'staff')
	const none = desk({ data: {} })
	for (const fields of [null, { email: 'a@x.nl' }, { organisation: 'o' }, { email: ' ', organisation: 'o' }]) {
		assert.deepEqual(await none.actions.invite(fields), { ok: false, message: 't:Give the address and the organisation.' })
	}
	assert.equal(none.calls.length, 0)
	const refused = desk({ reject: { response: { status: 503, data: { error: 'mail_not_sent' } } } })
	const answer = await refused.actions.invite({ email: 'a@x.nl', organisation: 'o' })
	assert.equal(answer.ok, false)
	assert.match(answer.message, /^t:/)
})

test('the invitation sentence carries the address and the formatted expiry', async () => {
	const words = []
	const actions = createStaffAccountActions({
		post: async () => ({ data: { expiresAt: '2026-12-01T10:00:00Z' } }),
		generateUrl: (path) => path,
		translate: (text, vars) => {
			words.push(vars)
			return text
		},
		formatDate: (iso) => `on ${iso}`,
	})
	await actions.invite({ email: 'a@x.nl', organisation: 'o' })
	assert.deepEqual(words.at(-1), { email: 'a@x.nl', date: 'on 2026-12-01T10:00:00Z' })
	const noExpiry = createStaffAccountActions({
		post: async () => ({ data: {} }),
		generateUrl: (path) => path,
		translate: (text, vars) => {
			words.push(vars)
			return text
		},
		formatDate: (iso) => `on ${iso}`,
	})
	await noExpiry.invite({ email: 'a@x.nl', organisation: 'o' })
	assert.deepEqual(words.at(-1), { email: 'a@x.nl', date: 'on ' })
})

#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// invitation-secrets.spec.mjs: how the account, contact and mandate
// invitations read their secret from the address, keep it over the sign-in,
// hand it back once and when they forget it, pinned for all three before
// they shared one module.
//
// Usage:
//   node --test tests/invitation-secrets.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	CLAIM_STORAGE_KEY,
	forgetClaimSecret,
	keepClaimSecret,
	keptClaimSecret,
	redeemKeptClaim,
} from '../src/shared/claimInvitation.js'
import {
	CONTACT_INVITATION_KEY,
	keepContactInvitation,
	redeemKeptContactInvitation,
} from '../src/shared/contactInvitation.js'
import {
	keepMandateInvitation,
	MANDATE_INVITATION_KEY,
	redeemKeptMandateInvitation,
} from '../src/shared/mandateInvitation.js'

/**
 * A storage double that records every call, and can refuse them all.
 *
 * @param {object} items What it holds.
 * @param {boolean} broken Whether every call throws.
 * @return {object} The storage, with `log`.
 */
function storage(items = {}, broken = false) {
	const log = []
	const act = (call, fn) => {
		log.push(call)
		if (broken) {
			throw new Error('blocked')
		}
		return fn()
	}
	return {
		log,
		items,
		getItem: (key) => act(['get', key], () => items[key] ?? null),
		setItem: (key, value) => act(['set', key, value], () => {
			items[key] = value
		}),
		removeItem: (key) => act(['remove', key], () => {
			delete items[key]
		}),
	}
}

/**
 * A history double.
 *
 * @param {boolean} broken Whether replaceState throws.
 * @return {object} The history, with `replaced`.
 */
function history(broken = false) {
	const replaced = []
	return {
		replaced,
		replaceState: (state, title, url) => {
			replaced.push([state, title, url])
			if (broken) {
				throw new Error('blocked')
			}
		},
	}
}

const KEEPERS = [
	['claim', keepClaimSecret, '#claim=', CLAIM_STORAGE_KEY],
	['contact', keepContactInvitation, '#contact-invitation=', CONTACT_INVITATION_KEY],
	['mandate', keepMandateInvitation, '#mandate-invitation=', MANDATE_INVITATION_KEY],
]

for (const [name, keep, prefix, key] of KEEPERS) {
	test(`${name}: the secret is read, the address cleaned and the secret kept`, () => {
		const store = storage()
		const h = history()
		assert.equal(keep({ hash: `${prefix}a%20b`, pathname: '/site', search: '?p=1' }, h, store), 'a b')
		assert.deepEqual(h.replaced, [[null, '', '/site?p=1']])
		assert.deepEqual(store.log, [['set', key, 'a b']])

		const other = storage()
		const h2 = history()
		assert.equal(keep({ hash: `${prefix}x&y=1` }, h2, other), '')
		assert.equal(keep({ hash: '#open=x' }, h2, other), '')
		assert.equal(keep(null, h2, other), '')
		assert.deepEqual([h2.replaced, other.log], [[], []])

		const bad = storage()
		const h3 = history()
		assert.equal(keep({ hash: `${prefix}%E0%A4%A` }, h3, bad), '')
		assert.deepEqual(h3.replaced, [[null, '', '']])
		assert.deepEqual(bad.log, [])

		const blocked = storage({}, true)
		assert.equal(keep({ hash: `${prefix}s`, pathname: '/p' }, history(true), blocked), 's')
		assert.deepEqual(blocked.log, [['set', key, 's']])
		assert.equal(keep({ hash: `${prefix}s` }, null, null), 's')
	})
}

test('claim: the kept secret is read and forgotten, also without storage', () => {
	assert.equal(keptClaimSecret(storage({ [CLAIM_STORAGE_KEY]: 'k' })), 'k')
	assert.equal(keptClaimSecret(storage()), '')
	assert.equal(keptClaimSecret(storage({}, true)), '')
	assert.equal(keptClaimSecret(null), '')
	const store = storage({ [CLAIM_STORAGE_KEY]: 'k' })
	forgetClaimSecret(store)
	assert.deepEqual(store.items, {})
	assert.doesNotThrow(() => forgetClaimSecret(storage({}, true)))
	assert.doesNotThrow(() => forgetClaimSecret(null))
})

const t = (key) => `t:${key}`

test('claim: redeemed once signed in; forgotten only after an answer about the invitation', async () => {
	assert.equal(await redeemKeptClaim({ api: {}, session: {}, t, storage: storage() }), null)
	const waiting = storage({ [CLAIM_STORAGE_KEY]: 'k' })
	assert.deepEqual(await redeemKeptClaim({ api: {}, session: null, t, storage: waiting }), {
		role: 'status', text: 't:Sign in to accept your invitation.', claimed: false,
	})
	assert.equal(waiting.items[CLAIM_STORAGE_KEY], 'k')
	const cases = [
		[{ ok: true, status: 200 }, false, true],
		[{ ok: false, status: 410, error: 'invitation_not_valid' }, false, false],
		[{ ok: false, status: 403, error: 'trust_too_low' }, true, false],
		[{ ok: false, status: 403, error: 'account_cannot_receive' }, true, false],
		[{ ok: false, status: 503, error: 'try_again' }, true, false],
		[{ ok: false, status: 0, error: '' }, true, false],
		[{ ok: false, status: 429, error: 'too_many_attempts' }, false, false],
		[null, true, false],
	]
	for (const [answer, kept, claimed] of cases) {
		const store = storage({ [CLAIM_STORAGE_KEY]: 'k' })
		const asked = []
		const outcome = await redeemKeptClaim({
			api: { claimInvitation: async (secret) => (asked.push(secret), answer) },
			session: {},
			t,
			storage: store,
		})
		assert.deepEqual(asked, ['k'])
		assert.equal(Object.hasOwn(store.items, CLAIM_STORAGE_KEY), kept, JSON.stringify(answer))
		assert.equal(outcome.claimed, claimed)
		assert.match(outcome.text, /^t:/)
	}
})

const REDEEMERS = [
	['contact', redeemKeptContactInvitation, CONTACT_INVITATION_KEY, 'You are now connected with the person who invited you.', '/contacts/accept-invitation'],
	['mandate', redeemKeptMandateInvitation, MANDATE_INVITATION_KEY, 'You may now arrange matters on behalf of the person who invited you.', '/mandates/invitations/accept'],
]

for (const [name, redeem, key, accepted, route] of REDEEMERS) {
	test(`${name}: redeemed once signed in; forgotten after a 2xx or 4xx answer`, async () => {
		const calls = []
		const api = (answer) => ({
			request: async (...args) => (calls.push(args), answer),
		})
		assert.equal(await redeem({ api: api({}), session: {}, t, storage: storage() }), null)
		assert.equal(await redeem({ api: api({}), session: {}, t, storage: storage({}, true) }), null)
		assert.deepEqual(await redeem({ api: api({}), session: null, t, storage: storage({ [key]: 'k' }) }), {
			role: 'status', text: 't:Sign in to accept your invitation.', claimed: false,
		})
		const cases = [
			[{ ok: true, status: 200 }, false, { role: 'status', text: `t:${accepted}`, claimed: true }],
			[{ ok: false, status: 400 }, false, { role: 'alert', text: 't:This invitation is no longer valid. Ask for a new one.', claimed: false }],
			[{ ok: false, status: 409 }, false, null],
			[{ ok: false, status: 499 }, false, { role: 'alert', text: 't:This invitation is no longer valid. Ask for a new one.', claimed: false }],
			[{ ok: false, status: 500 }, true, { role: 'alert', text: 't:This invitation is no longer valid. Ask for a new one.', claimed: false }],
			[{ ok: false, status: 0 }, true, { role: 'alert', text: 't:This invitation is no longer valid. Ask for a new one.', claimed: false }],
		]
		for (const [answer, kept, outcome] of cases) {
			const store = storage({ [key]: 'k' })
			const result = await redeem({ api: api(answer), session: {}, t, storage: store })
			assert.equal(Object.hasOwn(store.items, key), kept, `${answer.status}`)
			if (outcome) {
				assert.deepEqual(result, outcome)
			} else {
				assert.equal(result.claimed, false)
			}
		}
		const blocked = storage({ [key]: 'k' })
		blocked.removeItem = () => {
			throw new Error('blocked')
		}
		assert.equal((await redeem({ api: api({ ok: true, status: 200 }), session: {}, t, storage: blocked })).claimed, true)
		assert.deepEqual(calls[0], ['POST', route, { token: 'k' }])
		assert.ok(calls.every((call) => call[1] === route))
	})
}

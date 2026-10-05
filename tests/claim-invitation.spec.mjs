#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// claim-invitation.spec.mjs: an invitation link of a waiting account
// (invitation-secret-joins-the-signed-in-account). The site reads and strips
// `#claim=<secret>`, keeps it through the sign-in in sessionStorage, hands it
// back once the visitor is signed in, and shows one sentence for every dead
// invitation. The shell and the API adapter are wired in.
//
// Usage:
//   node --test tests/claim-invitation.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import {
	CLAIM_STORAGE_KEY,
	claimOutcome,
	forgetClaimSecret,
	keepClaimSecret,
	keptClaimSecret,
	redeemKeptClaim,
} from '../src/shared/claimInvitation.js'

/**
 * A sessionStorage stand-in.
 *
 * @return {object}
 */
function storage() {
	const map = new Map()
	return {
		getItem: (k) => (map.has(k) ? map.get(k) : null),
		setItem: (k, v) => map.set(k, String(v)),
		removeItem: (k) => map.delete(k),
		map,
	}
}

/**
 * An API adapter that records what it was asked and answers as told.
 *
 * @param {object} answer The answer to give.
 * @return {object}
 */
function api(answer) {
	const calls = []
	return {
		calls,
		claimInvitation: async (secret) => {
			calls.push(secret)
			return answer
		},
	}
}

const t = (key) => key

test('the secret is read from the fragment, stripped from the address and kept', () => {
	const store = storage()
	const replaced = []
	const history = { replaceState: (...args) => replaced.push(args) }
	const location = {
		hash: '#claim=abc%20123',
		pathname: '/apps/portaliq/site',
		search: '?portal=wilgenboom',
	}

	assert.equal(keepClaimSecret(location, history, store), 'abc 123')
	assert.deepEqual(replaced, [
		[null, '', '/apps/portaliq/site?portal=wilgenboom'],
	])
	assert.equal(store.getItem(CLAIM_STORAGE_KEY), 'abc 123')
	assert.equal(keptClaimSecret(store), 'abc 123')
})

test('another fragment is left alone', () => {
	const store = storage()
	const replaced = []
	const history = { replaceState: (...args) => replaced.push(args) }
	for (const hash of [
		'',
		'#token=abc',
		'#invitation=abc',
		'#claim=',
		'#open=a/b/c',
		'#claim=abc&token=x',
	]) {
		assert.equal(
			keepClaimSecret({ hash, pathname: '/', search: '' }, history, store),
			'',
			hash,
		)
	}
	assert.deepEqual(replaced, [])
	assert.equal(store.map.size, 0)
})

test('a browser without storage does not throw', () => {
	const refusing = {
		getItem: () => {
			throw new Error('denied')
		},
		setItem: () => {
			throw new Error('denied')
		},
		removeItem: () => {
			throw new Error('denied')
		},
	}
	const location = { hash: '#claim=abc', pathname: '/', search: '' }
	assert.equal(keepClaimSecret(location, null, refusing), 'abc')
	assert.equal(keepClaimSecret(location, null, null), 'abc')
	assert.equal(keptClaimSecret(refusing), '')
	assert.equal(keptClaimSecret(null), '')
	forgetClaimSecret(refusing)
	forgetClaimSecret(null)
})

test('without a kept invitation nothing is asked and nothing is shown', async () => {
	const adapter = api({ ok: true, status: 200, error: '' })
	assert.equal(
		await redeemKeptClaim({
			api: adapter,
			session: { subjectRef: 's' },
			t,
			storage: storage(),
		}),
		null,
	)
	assert.deepEqual(adapter.calls, [])
})

test('a visitor who is not signed in is asked to sign in, and the invitation is kept', async () => {
	const store = storage()
	store.setItem(CLAIM_STORAGE_KEY, 'abc')
	const adapter = api({ ok: true, status: 200, error: '' })

	const shown = await redeemKeptClaim({
		api: adapter,
		session: null,
		t,
		storage: store,
	})

	assert.deepEqual(shown, {
		role: 'status',
		text: 'Sign in to accept your invitation.',
		claimed: false,
	})
	assert.deepEqual(adapter.calls, [])
	assert.equal(keptClaimSecret(store), 'abc')
})

test('signed in, the invitation is handed back once and forgotten', async () => {
	const store = storage()
	store.setItem(CLAIM_STORAGE_KEY, 'abc')
	const adapter = api({ ok: true, status: 200, error: '' })
	const options = {
		api: adapter,
		session: { subjectRef: 's' },
		t,
		storage: store,
	}

	const shown = await redeemKeptClaim(options)

	assert.deepEqual(shown, {
		role: 'status',
		text: 'Your invitation is accepted. You now see what is shared with you.',
		claimed: true,
	})
	assert.deepEqual(adapter.calls, ['abc'])
	assert.equal(keptClaimSecret(store), '')
	assert.equal(await redeemKeptClaim(options), null)
	assert.deepEqual(adapter.calls, ['abc'])
})

test('a dead invitation is one sentence and is forgotten', async () => {
	const store = storage()
	store.setItem(CLAIM_STORAGE_KEY, 'abc')

	const shown = await redeemKeptClaim({
		api: api({ ok: false, status: 403, error: 'invitation_not_valid' }),
		session: { subjectRef: 's' },
		t,
		storage: store,
	})

	assert.deepEqual(shown, {
		role: 'alert',
		text: 'This invitation is no longer valid. Ask for a new one.',
		claimed: false,
	})
	assert.equal(keptClaimSecret(store), '')
})

test('each refusal has its own sentence, and an unknown one a general sentence', () => {
	assert.equal(
		claimOutcome({ ok: false, status: 429, error: 'too_many_attempts' }).text,
		'Too many attempts. Try again in an hour.',
	)
	assert.equal(
		claimOutcome({ ok: false, status: 403, error: 'trust_too_low' }).text,
		'You need a more secure way to sign in for this invitation.',
	)
	assert.equal(
		claimOutcome({ ok: false, status: 500, error: '' }).text,
		'That did not work. Try again later.',
	)
	assert.equal(claimOutcome(null).role, 'alert')
})

test('a server that could not be reached keeps the invitation for the next page', async () => {
	const store = storage()
	store.setItem(CLAIM_STORAGE_KEY, 'abc')

	const shown = await redeemKeptClaim({
		api: api({ ok: false, status: 0, error: '' }),
		session: { subjectRef: 's' },
		t,
		storage: store,
	})

	assert.equal(shown.text, 'That did not work. Try again later.')
	assert.equal(keptClaimSecret(store), 'abc')
})

test('every sentence is in both site bundles, without an em-dash', () => {
	const en = JSON.parse(readFileSync('src/shared/i18n/en.json', 'utf8'))
	const nl = JSON.parse(readFileSync('src/shared/i18n/nl.json', 'utf8'))
	const keys = [
		'Sign in to accept your invitation.',
		'Your invitation is accepted. You now see what is shared with you.',
		'This invitation is no longer valid. Ask for a new one.',
		'Too many attempts. Try again in an hour.',
		'You need a more secure way to sign in for this invitation.',
		'That did not work. Try again later.',
	]
	for (const key of keys) {
		assert.equal(en[key], key, key)
		assert.ok(nl[key] && nl[key] !== key, key)
		assert.ok(!nl[key].includes('—'), key)
	}
})

test('the shell keeps the secret at boot, redeems it before the account loads and forgets it at sign-out', () => {
	const shell = readFileSync('src/site/App.vue', 'utf8')
	assert.match(shell, /keepClaimSecret\(window\.location, window\.history/)
	const redeem = shell.indexOf('this.claimMessage = await redeemKeptClaim(')
	const load = shell.indexOf('await this.loadAccount()', redeem)
	assert.ok(redeem > 0 && load > redeem)
	assert.match(shell, /forgetClaimSecret\(this\.claimStorage\(\)\)/)
	assert.match(shell, /data-testid="site-claim-invitation"/)
})

test('the API adapter posts the secret to the redeem route', () => {
	const adapter = readFileSync('src/shared/portalApi.js', 'utf8')
	assert.match(
		adapter,
		/claimInvitation\(secret\) \{\s+return answer\('POST', '\/identity\/invitation\/redeem', \{ secret \}\)/,
	)
})

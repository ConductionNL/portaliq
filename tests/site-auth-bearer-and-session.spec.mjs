#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-auth-bearer-and-session.spec.mjs: which bearer the site
// (src/site/lib/authApi.js) adopts — from a sign-in link's fragment, this
// tab's store, or the retired portal's localStorage, once — what it cleans up
// after, and when reading or rotating the session answers and keeps a
// bearer; pinned with allowed and refused outcomes before these were split
// into named steps.
//
// Usage:
//   node --test tests/site-auth-bearer-and-session.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	adoptLegacyToken,
	adoptSessionToken,
	fetchSession,
	LEGACY_TOKEN_KEY,
	refreshSession,
} from '../src/site/lib/authApi.js'

/** Where this tab keeps its bearer (authApi.js keeps the key to itself). */
const TOKEN_KEY = 'portaliq.session.token'

/**
 * A storage that keeps what it is given, notes writes, and can refuse.
 *
 * @param {object} items What it holds.
 * @param {object} refuse Which calls throw: `get`, `set`, `remove`.
 * @return {object} The storage.
 */
function storage(items = {}, refuse = {}) {
	const kept = { ...items }
	return {
		kept,
		getItem(key) {
			if (refuse.get) {
				throw new Error('refused')
			}
			return Object.hasOwn(kept, key) ? kept[key] : null
		},
		setItem(key, value) {
			if (refuse.set) {
				throw new Error('refused')
			}
			kept[key] = String(value)
		},
		removeItem(key) {
			if (refuse.remove) {
				throw new Error('refused')
			}
			delete kept[key]
		},
	}
}

/**
 * A window with a location, a history that notes replacements, and stores.
 *
 * @param {object} setup `hash`, `session`, `local` storages.
 * @return {object} The window, with `replaced` listing the cleaned addresses.
 */
function browser(setup = {}) {
	const win = {
		location: { hash: setup.hash ?? '', pathname: '/site', search: '?route=/mijn' },
		history: { replaceState: (state, title, url) => win.replaced.push(url) },
		replaced: [],
		sessionStorage: setup.session ?? storage(),
		localStorage: setup.local ?? storage(),
	}
	globalThis.window = win
	return win
}

test('a sign-in link hands its bearer over, into this tab, and leaves the address clean', () => {
	const win = browser({ hash: '#route=/mijn&token=abc%2Bdef' })
	assert.equal(adoptSessionToken(), 'abc+def')
	assert.deepEqual(win.sessionStorage.kept, { [TOKEN_KEY]: 'abc+def' })
	assert.deepEqual(win.replaced, ['/site?route=/mijn'])
	const refused = browser({ hash: '#token=t', session: storage({}, { set: true }) })
	assert.equal(adoptSessionToken(), 't', 'without storage the bearer lasts the page view')
	assert.deepEqual(refused.replaced, ['/site?route=/mijn'])
	browser({ hash: '#token=%E0%A4%A' })
	assert.throws(() => adoptSessionToken(), URIError)
})

test('without a link the tab keeps its own bearer, else the adopted old one, else none', () => {
	browser({ session: storage({ [TOKEN_KEY]: 'tab' }) })
	assert.equal(adoptSessionToken(), 'tab')
	const old = browser({ local: storage({ [LEGACY_TOKEN_KEY]: 'old' }) })
	assert.equal(adoptSessionToken(), 'old')
	assert.deepEqual(old.localStorage.kept, {}, 'the old bearer leaves localStorage')
	assert.deepEqual(old.sessionStorage.kept, { [TOKEN_KEY]: 'old' })
	assert.deepEqual(old.replaced, [])
	browser({ hash: '#tokens=x' })
	assert.equal(adoptSessionToken(), '')
	browser({ session: storage({}, { get: true, set: true }), local: storage({ [LEGACY_TOKEN_KEY]: 'old' }) })
	assert.equal(adoptSessionToken(), 'old', 'a refusing tab store falls back to the old bearer')
	browser({ session: storage({}, { get: true }) })
	assert.equal(adoptSessionToken(), '')
	delete globalThis.window
	assert.equal(adoptSessionToken(), '', 'no window, no bearer')
})

test('the old bearer moves once, and never over a bearer this tab holds', () => {
	const held = browser({ session: storage({ [TOKEN_KEY]: 'new' }), local: storage({ [LEGACY_TOKEN_KEY]: 'old' }) })
	assert.equal(adoptLegacyToken(), 'old')
	assert.deepEqual(held.sessionStorage.kept, { [TOKEN_KEY]: 'new' })
	assert.deepEqual(held.localStorage.kept, {})
	assert.equal(adoptLegacyToken(), '', 'once')
	browser({ local: storage({}, { get: true }) })
	assert.equal(adoptLegacyToken(), '')
	const noRemove = browser({ local: storage({ [LEGACY_TOKEN_KEY]: 'old' }, { remove: true }) })
	assert.equal(adoptLegacyToken(), '', 'a bearer that cannot leave localStorage is not adopted')
	assert.deepEqual(noRemove.sessionStorage.kept, {})
	const noSession = browser({ local: storage({ [LEGACY_TOKEN_KEY]: 'old' }), session: storage({}, { get: true }) })
	assert.equal(adoptLegacyToken(), 'old', 'without a tab store the bearer lasts the page view')
	assert.deepEqual(noSession.localStorage.kept, {})
	browser({ local: storage({ [LEGACY_TOKEN_KEY]: '' }) })
	assert.equal(adoptLegacyToken(), '')
})

/**
 * A fetch that answers from `respond` and notes each call.
 *
 * @param {(url: string) => object|Error} respond The answer, or an Error to throw.
 * @return {Array<object>} The calls.
 */
function answering(respond) {
	const calls = []
	globalThis.fetch = async (url, init) => {
		calls.push({ url, init })
		const answer = respond(url)
		if (answer instanceof Error) {
			throw answer
		}
		return answer
	}
	return calls
}

function response (status, body) {
  return { ok: status >= 200 && status < 300, status, json: async () => {
	if (body instanceof Error) {
		throw body
	}
	return body
} }
}

test('the session is the edge\'s answer only when it says authenticated', async () => {
	browser({ session: storage({ [TOKEN_KEY]: 'tab' }) })
	const calls = answering(() => response(200, { authenticated: true, subjectRef: 's' }))
	assert.deepEqual(await fetchSession('/portal/api'), { authenticated: true, subjectRef: 's' })
	assert.deepEqual(calls[0], { url: '/portal/api/session', init: { headers: { Accept: 'application/json', Authorization: 'Bearer tab' }, credentials: 'include' } })
	for (const answer of [response(200, { authenticated: false }), response(200, null), response(401, { authenticated: true }), response(200, new Error('x')), new Error('offline')]) {
		answering(() => answer)
		assert.equal(await fetchSession('/portal/api'), null)
	}
	browser()
	const anonymous = answering(() => response(200, { authenticated: true }))
	await fetchSession('/a')
	assert.deepEqual(anonymous[0].init.headers, { Accept: 'application/json' })
	browser({ hash: '#token=%E0%A4%A' })
	assert.equal(await fetchSession('/a'), null, 'a broken link answers no session')
})

test('a rotation keeps the new bearer, and a refusal changes nothing', async () => {
	const win = browser({ session: storage({ [TOKEN_KEY]: 'tab' }) })
	const calls = answering(() => response(200, { token: 'rotated', expiresAt: 9 }))
	assert.deepEqual(await refreshSession('/portal/api'), { token: 'rotated', expiresAt: 9 })
	assert.equal(win.sessionStorage.kept[TOKEN_KEY], 'rotated')
	assert.deepEqual(calls[0], { url: '/portal/api/session/refresh', init: { method: 'POST', headers: { Accept: 'application/json', Authorization: 'Bearer tab' }, credentials: 'include' } })
	for (const answer of [response(401, { token: 'x' }), response(200, {}), response(200, null), response(200, new Error('x')), new Error('offline')]) {
		const kept = browser({ session: storage({ [TOKEN_KEY]: 'tab' }) })
		answering(() => answer)
		assert.equal(await refreshSession('/portal/api'), null)
		assert.equal(kept.sessionStorage.kept[TOKEN_KEY], 'tab')
	}
	browser()
	const none = answering(() => response(200, { token: 'x' }))
	assert.equal(await refreshSession('/a'), null)
	assert.deepEqual(none, [], 'no bearer, no rotation asked')
	const locked = { [TOKEN_KEY]: 'tab' }
	browser({ session: { getItem: (key) => locked[key] ?? null, setItem() { throw new Error('refused') } } })
	answering(() => response(200, { token: 'rotated' }))
	assert.deepEqual(await refreshSession('/a'), { token: 'rotated' }, 'without storage the answer still counts')
})

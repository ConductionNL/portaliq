#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-shell-silent-sign-in.spec.mjs: when the site shell (src/site/App.vue)
// tries a silent sign-in and when it does not: once per browser session,
// where the organisation turned it on, never after a failed sign-in or an
// inactivity sign-out, and never where the browser refuses its storage; the
// login returns to the page. Pinned before trySilentSignIn was split into
// named steps.
//
// Usage:
//   node --test tests/site-shell-silent-sign-in.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { sfcOptionsLoaded } from './support/sfc-options-loaded.mjs'

const App = await sfcOptionsLoaded('src/site/App.vue')

/**
 * A sessionStorage that keeps what it is given.
 *
 * @return {object} The storage.
 */
function memory() {
	const items = {}
	return {
		items,
		getItem: (key) => (Object.hasOwn(items, key) ? items[key] : null),
		setItem: (key, value) => { items[key] = String(value) },
	}
}

/**
 * Try a silent sign-in on a stand-in shell.
 *
 * @param {object} state The shell's state, over a portal that turned it on.
 * @param {object|null|Error} storage The browser's sessionStorage, or an Error it throws on access.
 * @return {Array<string>} The addresses the browser was sent to.
 */
function attempt(state = {}, storage = memory()) {
	const assigned = []
	const win = {
		location: { pathname: '/site', search: '?route=/afval', assign: (url) => assigned.push(url) },
	}
	Object.defineProperty(win, 'sessionStorage', {
		get() {
			if (storage instanceof Error) {
				throw storage
			}
			return storage
		},
	})
	globalThis.window = win
	const shell = Object.assign(Object.create(App.methods), {
		signinFailed: false,
		idleSignedOut: false,
		signinConfig: { silentSignIn: 'digid', signinOrganisation: 'gemeente-x' },
		site: { slug: 'demo' },
		portalSlug: 'fallback',
		...state,
	})
	shell.trySilentSignIn()
	return assigned
}

const ONCE = '/portal/api/session/oidc/start?org=gemeente-x&provider=digid&portal=demo&silent=1&returnTo=%2Fsite%3Froute%3D%2Fafval'

test('a portal with silent sign-in sends the visitor once per browser session, and back to the page', () => {
	const store = memory()
	assert.deepEqual(attempt({}, store), [ONCE])
	assert.deepEqual(Object.values(store.items), ['1'], 'the attempt is remembered')
	assert.deepEqual(attempt({}, store), [], 'never twice in one browser session')
})

test('the organisation slug falls back from the site to the portal, else none', () => {
	assert.deepEqual(attempt({ site: { slug: '' } }), [ONCE.replace('portal=demo', 'portal=fallback')])
	const none = attempt({ site: {}, portalSlug: '' })
	assert.equal(none.length, 1)
	assert.doesNotMatch(none[0], /portal=/)
	const noOrganisation = attempt({ signinConfig: { silentSignIn: 'digid' } })
	assert.equal(noOrganisation.length, 1)
	assert.doesNotMatch(noOrganisation[0], /org=gemeente-x/)
})

test('no silent sign-in after a failed sign-in, an inactivity sign-out, without the setting or without storage', () => {
	const store = memory()
	assert.deepEqual(attempt({ signinFailed: true }, store), [])
	assert.deepEqual(attempt({ idleSignedOut: true }, store), [])
	assert.deepEqual(store.items, {}, 'a refused attempt is not remembered')
	assert.deepEqual(attempt({ signinConfig: {} }), [])
	assert.deepEqual(attempt({ signinConfig: { silentSignIn: '' } }), [])
	assert.deepEqual(attempt({}, null), [])
	assert.deepEqual(attempt({}, new Error('storage refused')), [])
})

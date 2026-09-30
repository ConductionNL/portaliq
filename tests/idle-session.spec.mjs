#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// idle-session.spec.mjs: the idle window in the browser, the warning before
// sign-out, silent sign-in once per browser session and the broker's sign-out
// address (signin-session-idle-warning-and-sso T03-T06, T09, T11, T12).
//
// Usage:
//   node --test tests/idle-session.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	canExtend,
	IDLE_WARNING_STRINGS,
	logoutTarget,
	markIdleSignOut,
	remainingText,
	shouldRefresh,
	silentSignInUrl,
	takeIdleSignOut,
	warningDelayMs,
	warningLeadSeconds,
} from '../src/portal/lib/idleSession.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const t = (key, vars = {}) => key.replace(/\{(\w+)\}/g, (_, name) => String(vars[name] ?? ''))

/**
 * An in-memory Storage.
 *
 * @return {object} The store.
 */
function memoryStore() {
	const data = {}
	return {
		getItem: (k) => (k in data ? data[k] : null),
		setItem: (k, v) => { data[k] = String(v) },
		removeItem: (k) => { delete data[k] },
	}
}

const NOW = 1_000_000

test('the warning opens two minutes before expiry, or at 40 percent of a short window', () => {
	assert.equal(warningLeadSeconds(900), 120)
	assert.equal(warningLeadSeconds(300), 120)
	assert.equal(warningLeadSeconds(200), 80)
	assert.equal(warningDelayMs({ expiresAt: NOW + 900, idleTimeout: 900 }, NOW), 780_000)
	assert.equal(warningDelayMs({ expiresAt: NOW + 60, idleTimeout: 900 }, NOW), 0)
})

test('only activity since the last refresh, in the second half of the window, refreshes', () => {
	const times = { expiresAt: NOW + 400, hardExpiresAt: NOW + 20_000, idleTimeout: 900 }
	assert.equal(shouldRefresh(times, NOW, NOW - 10, NOW - 500), true, 'typed since the last refresh')
	assert.equal(shouldRefresh(times, NOW, NOW - 600, NOW - 500), false, 'idle since the last refresh')
	assert.equal(shouldRefresh({ ...times, expiresAt: NOW + 500 }, NOW, NOW - 10, NOW - 500), false, 'first half of the window')
	assert.equal(shouldRefresh({ ...times, hardExpiresAt: NOW - 1 }, NOW, NOW - 10, NOW - 500), false, 'past the cap')
	assert.equal(shouldRefresh(null, NOW, NOW - 10, NOW - 500), false, 'no session times')
})

test('the session can be extended only before the cap', () => {
	assert.equal(canExtend({ expiresAt: NOW + 120, hardExpiresAt: NOW + 60 }, NOW), true)
	assert.equal(canExtend({ expiresAt: NOW + 120, hardExpiresAt: NOW }, NOW), false)
})

test('the remaining time reads in whole minutes, then seconds', () => {
	assert.equal(remainingText(120, t), '2 minutes')
	assert.equal(remainingText(61, t), '2 minutes')
	assert.equal(remainingText(60, t), '1 minute')
	assert.equal(remainingText(45, t), '45 seconds')
	assert.equal(remainingText(-3, t), '0 seconds')
})

test('an inactivity sign-out is remembered once for the login screen', () => {
	const store = memoryStore()
	assert.equal(takeIdleSignOut(store), false)
	markIdleSignOut(store)
	assert.equal(takeIdleSignOut(store), true)
	assert.equal(takeIdleSignOut(store), false)
	assert.equal(takeIdleSignOut(null), false)
})

test('silent sign-in is tried once per browser session, and only when turned on', () => {
	const store = memoryStore()
	const config = { apiBase: '/api', organisationSlug: 'gemeente-x', silentSignIn: 'digid' }
	assert.equal(silentSignInUrl(config, store), '/api/session/oidc/start?org=gemeente-x&provider=digid&portal=gemeente-x&silent=1')
	assert.equal(silentSignInUrl(config, store), '', 'a second load does not try again')
	assert.equal(silentSignInUrl({ ...config, silentSignIn: '' }, memoryStore()), '', 'off')
	assert.equal(silentSignInUrl(config, null), '', 'no storage, no attempt: it could loop')
})

test('only an http(s) broker sign-out address is followed', () => {
	assert.equal(logoutTarget({ ok: true, logoutUrl: 'https://broker.example/logout?client_id=x' }), 'https://broker.example/logout?client_id=x')
	assert.equal(logoutTarget({ ok: true }), '')
	assert.equal(logoutTarget({ ok: true, logoutUrl: 'javascript:alert(1)' }), '')
	assert.equal(logoutTarget(null), '')
})

test('every idle string is translated for every locale the portal ships', () => {
	for (const locale of ['nl', 'en']) {
		const strings = JSON.parse(readFileSync(join(ROOT, 'src', 'portal', 'i18n', `${locale}.json`), 'utf8'))
		for (const key of IDLE_WARNING_STRINGS) {
			assert.ok(typeof strings[key] === 'string' && strings[key] !== '', `${locale}: ${key}`)
		}
	}
})

test('the portal SPA refreshes on activity, warns in its own dialog and follows the broker sign-out', () => {
	const app = readFileSync(join(ROOT, 'src', 'portal', 'App.jsx'), 'utf8')
	assert.doesNotMatch(app, /REFRESH_INTERVAL_MS/, 'the fixed interval is gone')
	assert.match(app, /import IdleWarningDialog from '@portal\/components\/IdleWarningDialog\.jsx'/)
	assert.match(app, /useIdleSession\(/)
	assert.match(app, /silentSignInUrl\(/)
	assert.match(app, /logoutTarget\(/)
	const dialog = readFileSync(join(ROOT, 'src', 'portal', 'components', 'IdleWarningDialog.jsx'), 'utf8')
	assert.match(dialog, /role="alertdialog"/)
	assert.match(dialog, /aria-live="polite"/)
})

test('the site renderer refreshes on activity, warns in its own dialog and follows the broker sign-out', () => {
	const app = readFileSync(join(ROOT, 'src', 'site', 'App.vue'), 'utf8')
	assert.match(app, /import IdleWarningDialog from '\.\/components\/IdleWarningDialog\.vue'/)
	assert.match(app, /logoutTarget\(/)
	const dialog = readFileSync(join(ROOT, 'src', 'site', 'components', 'IdleWarningDialog.vue'), 'utf8')
	assert.match(dialog, /role="alertdialog"/)
	assert.match(dialog, /aria-live="polite"/)
	const auth = readFileSync(join(ROOT, 'src', 'site', 'lib', 'authApi.js'), 'utf8')
	assert.match(auth, /export async function refreshSession\(/)
})

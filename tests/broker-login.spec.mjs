#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// broker-login.spec.mjs: the portal's login buttons follow the route the
// organisation chose, and a failed login shows one sentence
// (signin-integriq-broker-login T09, T10).
//
// Usage:
//   node --test tests/broker-login.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { consumeSigninFailed, loginStartUrl } from '../src/portal/lib/signinRoute.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')

test('a broker-routed provider starts at the broker start, the rest at the OIDC start', () => {
	const base = '/apps/portaliq/portal/api'
	assert.equal(
		loginStartUrl(base, 'gemeente-x', 'digid', 'broker'),
		'/apps/portaliq/portal/api/session/broker/start?org=gemeente-x&provider=digid',
	)
	assert.equal(
		loginStartUrl(base, 'gemeente-x', 'eherkenning', 'oidc'),
		'/apps/portaliq/portal/api/session/oidc/start?org=gemeente-x&provider=eherkenning',
	)
	assert.equal(
		loginStartUrl(base, 'gemeente-x', 'digid', undefined),
		'/apps/portaliq/portal/api/session/oidc/start?org=gemeente-x&provider=digid',
		'an entry without a route keeps the OIDC route',
	)
})

test('a failed login is read once from the fragment and stripped', () => {
	const replaced = []
	const history = { replaceState: (_s, _t, url) => replaced.push(url) }
	const location = {
		hash: '#signin=failed',
		pathname: '/apps/portaliq/portal',
		search: '?org=venray',
	}

	assert.equal(consumeSigninFailed(location, history), true)
	assert.deepEqual(replaced, ['/apps/portaliq/portal?org=venray'])
	assert.equal(
		consumeSigninFailed({ ...location, hash: '#token=abc' }, history),
		false,
	)
	assert.equal(replaced.length, 1)
})

test('the login screen starts each button by its route and shows the failure', () => {
	const app = readFileSync(join(ROOT, 'src', 'portal', 'App.jsx'), 'utf8')
	assert.match(app, /api\.loginStartUrl\(p\.provider, p\.route\)/)
	assert.match(app, /consumeSigninFailed\(window\.location, window\.history\)/)
	assert.match(
		app,
		/t\('Signing in did not work\. Try again or choose another way in\.'\)/,
	)

	const nl = JSON.parse(
		readFileSync(join(ROOT, 'src', 'portal', 'i18n', 'nl.json'), 'utf8'),
	)
	assert.equal(
		nl['Signing in did not work. Try again or choose another way in.'],
		'Inloggen is niet gelukt. Probeer het opnieuw of kies een andere manier.',
	)
})

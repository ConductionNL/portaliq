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
import {
	consumeSigninFailed,
	loginStartUrl,
	signinOrganisation,
} from '../src/shared/signinRoute.js'
import { signInRoutes, takeSigninFailed } from '../src/site/lib/authApi.js'

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

test('site: the login screen starts each button at the edge for its portal and shows the failure', () => {
	const routes = signInRoutes(
		{
			slug: 'wilgenboom',
			authentication: { modes: ['public', 'digid', 'oidc'] },
		},
		'/apps/portaliq/portal/api',
	)
	assert.deepEqual(
		routes.map((route) => route.mode),
		['digid', 'oidc'],
		'public is not a sign-in route',
	)
	for (const route of routes) {
		assert.match(
			route.href,
			/^\/apps\/portaliq\/portal\/api\/session\/oidc\/start\?provider=/,
		)
		assert.match(
			route.href,
			/[?&]portal=wilgenboom(&|$)/,
			'the serving portal travels with the link',
		)
	}
	assert.match(routes[1].href, /provider=generic/, 'a mode is not a provider')

	const app = readFileSync(join(ROOT, 'src', 'site', 'App.vue'), 'utf8')
	assert.match(app, /signinFailed: takeSigninFailed\(\)/)
	assert.match(
		app,
		/:signinFailedMessage="signinFailed \? signinFailedMessage : ''"/,
	)
	assert.match(
		app,
		/this\.t\(\s*'Signing in did not work\. Try again or choose another way in\.',?\s*\)/,
	)

	const nl = JSON.parse(
		readFileSync(join(ROOT, 'src', 'shared', 'i18n', 'nl.json'), 'utf8'),
	)
	assert.equal(
		nl['Signing in did not work. Try again or choose another way in.'],
		'Inloggen is niet gelukt. Probeer het opnieuw of kies een andere manier.',
	)
})

test('site: a failed sign-in is read once from the fragment and stripped', () => {
	const replaced = []
	const saved = globalThis.window
	globalThis.window = {
		location: {
			hash: '#signin=failed',
			pathname: '/apps/portaliq/site',
			search: '?portal=venray',
		},
		history: { replaceState: (_s, _t, url) => replaced.push(url) },
	}
	try {
		assert.equal(takeSigninFailed(), true)
		assert.deepEqual(replaced, ['/apps/portaliq/site?portal=venray'])
		globalThis.window.location.hash = '#token=abc'
		assert.equal(takeSigninFailed(), false)
		assert.equal(replaced.length, 1)
	} finally {
		globalThis.window = saved
	}
})

test('the login starts with the sign-in organisation, not the portal slug (portal-signin-on-its-own-address T1)', () => {
	assert.equal(
		signinOrganisation({
			organisationSlug: 'wilgenboom',
			signinOrganisation: 'default-organisation',
		}),
		'default-organisation',
	)
	assert.equal(
		signinOrganisation({
			organisationSlug: 'gemeente-x',
			signinOrganisation: '',
		}),
		'gemeente-x',
		'an older server without the key keeps the old behaviour',
	)
	assert.equal(signinOrganisation({}), '')
})

test('site: the dev login button shows only where the server accepts it (portal-signin-on-its-own-address T2)', () => {
	const app = readFileSync(join(ROOT, 'src', 'site', 'App.vue'), 'utf8')
	assert.match(app, /:devLogin="signinConfig\.devLogin === true"/)
	const area = readFileSync(
		join(ROOT, 'src', 'site', 'components', 'AccountArea.vue'),
		'utf8',
	)
	assert.match(area, /v-if="devLogin"[\s\S]*?data-testid="site-devlogin"/)
})

test('the login names the serving portal so it returns there (portal-signin-on-its-own-address T3)', () => {
	assert.equal(
		loginStartUrl('/api', 'default-organisation', 'digid', 'oidc', 'wilgenboom'),
		'/api/session/oidc/start?org=default-organisation&provider=digid&portal=wilgenboom',
	)
	assert.equal(
		loginStartUrl('/api', 'org', 'digid', 'oidc', ''),
		'/api/session/oidc/start?org=org&provider=digid',
	)
})

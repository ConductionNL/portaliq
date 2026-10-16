// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// password-reset-from-the-sign-in-page (REQ-PWR-001): "Wachtwoord vergeten"
// shows under the account route's button when the portal offers that route,
// and nowhere else. It leads to the address the server served; portaliq asks
// for no password of its own.
//
// Usage:
//   node --test tests/password-reset-link.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { signInRoutes } from '../src/site/lib/authApi.js'
import { mountSfc } from './support/mount-sfc.mjs'

const LOST = 'https://nc.example/index.php/login?redirect_url=%2Fx'

test('the account route carries the reset address, another route does not', () => {
	const site = {
		slug: 'p',
		lostPasswordUrl: LOST,
		authentication: { modes: ['nextcloud', 'digid'] },
	}
	const routes = signInRoutes(site, '/api')
	assert.equal(routes.find((r) => r.mode === 'nextcloud').lostPasswordUrl, LOST)
	assert.equal(routes.find((r) => r.mode === 'digid').lostPasswordUrl, undefined)
})

test('a portal without the address gets no link', () => {
	assert.equal(
		signInRoutes({ authentication: { modes: ['nextcloud'] } }, '/api')[0]
			.lostPasswordUrl,
		undefined,
	)
})

// demo-sign-in-beside-the-account: the demo cards sit beside the account
// route, so a resident with an account can still reset its password there.
test('on the demo the account route keeps the link, the demo cards get none', () => {
	const routes = signInRoutes(
		{ lostPasswordUrl: LOST, authentication: { modes: ['nextcloud'] } },
		'/api',
		undefined,
		'zuiddrecht',
		[{ key: 'sanne', name: 'Sanne de Vries' }],
	)
	assert.equal(routes[0].lostPasswordUrl, LOST)
	assert.equal(routes[1].demo, true)
	assert.equal(routes[1].lostPasswordUrl, undefined)
})

test('the sign-in page shows the link under the account button only', async () => {
	globalThis.window = { location: { pathname: '/site', search: '' } }
	const page = await mountSfc('src/site/components/chrome/SignInPage.vue', {
		routes: [
			{
				mode: 'nextcloud',
				label: 'Inloggen',
				href: '/a',
				lostPasswordUrl: LOST,
			},
			{ mode: 'digid', label: 'DigiD', href: '/b' },
		],
	})
	await page.flush()
	const links = page.findAll(
		(n) => n.props['data-testid'] === 'site-signin-lost-password',
	)
	assert.equal(links.length, 1)
	assert.equal(links[0].props.href, LOST)
	assert.match(page.textOf(links[0]), /Wachtwoord vergeten/)
})

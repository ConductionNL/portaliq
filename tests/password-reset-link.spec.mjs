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

test('a portal without the address, or on the demo, gets no link', () => {
	assert.equal(
		signInRoutes({ authentication: { modes: ['nextcloud'] } }, '/api')[0]
			.lostPasswordUrl,
		undefined,
	)
	assert.equal(
		signInRoutes(
			{ lostPasswordUrl: LOST, authentication: { modes: ['nextcloud'] } },
			'/api',
			undefined,
			'sanne',
		)[0].lostPasswordUrl,
		undefined,
	)
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

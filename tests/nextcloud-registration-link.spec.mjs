// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// nextcloud-account-registration (REQ-NCL-005): "Nog geen account? Maak een
// account aan" shows on the account route's card when the server served a
// registration address, and nowhere else. The address is the one the server
// built; the site never makes one up. The e-mail link never carries it
// (REQ-IWI-014).
//
// Usage:
//   node --test tests/nextcloud-registration-link.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { signInRoutes } from '../src/site/lib/authApi.js'
import { mountSfc } from './support/mount-sfc.mjs'

const REGISTER = 'https://nc.example/index.php/apps/registration/'

test('the account route carries the registration address, another route does not', () => {
	const site = {
		slug: 'p',
		registerUrl: REGISTER,
		authentication: { modes: ['nextcloud', 'digid', 'email-link'] },
	}
	const routes = signInRoutes(site, '/api', undefined, '', '', true)
	assert.equal(routes.find((r) => r.mode === 'nextcloud').registerUrl, REGISTER)
	assert.equal(routes.find((r) => r.mode === 'digid').registerUrl, undefined)
	assert.equal(routes.find((r) => r.mode === 'email-link').registerUrl, undefined)
})

test('a portal without the address, or on the demo, gets no link', () => {
	assert.equal(
		signInRoutes({ authentication: { modes: ['nextcloud'] } }, '/api')[0]
			.registerUrl,
		undefined,
	)
	assert.equal(
		signInRoutes(
			{ registerUrl: REGISTER, authentication: { modes: ['nextcloud'] } },
			'/api',
			undefined,
			'sanne',
		)[0].registerUrl,
		undefined,
	)
})

test('an e-mail link portal never draws the link, whatever the config says', () => {
	const routes = signInRoutes(
		{ registerUrl: REGISTER, authentication: { modes: ['email-link'] } },
		'/api',
		undefined,
		'',
		'',
		true,
	)
	assert.equal(routes.length, 1)
	assert.equal(routes[0].registerUrl, undefined)
})

test('the sign-in page shows the link on the account card only', async () => {
	globalThis.window = { location: { pathname: '/site', search: '' } }
	const page = await mountSfc('src/site/components/chrome/SignInPage.vue', {
		routes: [
			{
				mode: 'nextcloud',
				label: 'Inloggen',
				href: '/a',
				registerUrl: REGISTER,
			},
			{ mode: 'digid', label: 'DigiD', href: '/b' },
		],
	})
	await page.flush()
	const links = page.findAll(
		(n) => n.props['data-testid'] === 'site-signin-register',
	)
	assert.equal(links.length, 1)
	assert.equal(links[0].props.href, REGISTER)
	assert.match(page.textOf(links[0]), /Account aanmaken/)
})

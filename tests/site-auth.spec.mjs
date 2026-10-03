#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-auth.spec.js — the renderer's sign-in derivation.
//
// Usage:
//   node tests/site-auth.spec.js
//
// The decision under test is which sign-in routes a portal offers, derived
// from the `authentication.modes` it DECLARES on the public content API. The
// case that matters most is the negative one: a portal declaring only
// `public` must offer NOTHING. An inert "Sign in" button on a portal with no
// accounts is a support ticket from every visitor who presses it, and it is
// the shape a naive `modes.length > 0` check produces.
//
// Run as a plain node script to match tests/registry.spec.js and
// tests/manifest-v2.spec.js — this app has no JS test runner, and adding one
// for three functions would be a bigger change than the thing being tested.

import {
	adoptLegacyToken,
	adoptSessionToken,
	authBaseFrom,
	clearSessionToken,
	LEGACY_TOKEN_KEY,
	SIGNIN_FAILED_MESSAGE,
	signInRoutes,
	takeSigninFailed,
} from '../src/site/lib/authApi.js'

let failures = 0

/**
 * Assert deep equality, reporting the difference rather than a bare boolean.
 *
 * @param {string} what     What is being asserted.
 * @param {*}      actual   The value produced.
 * @param {*}      expected The value wanted.
 * @return {void}
 */
function assertEqual(what, actual, expected) {
	const a = JSON.stringify(actual)
	const e = JSON.stringify(expected)
	if (a === e) {
		console.log(`  ok   ${what}`)
		return
	}
	console.error(`  FAIL ${what}\n       expected ${e}\n       actual   ${a}`)
	failures += 1
}

console.log('authBaseFrom')
assertEqual(
	'derives the auth edge from the content API base',
	authBaseFrom('/index.php/apps/portaliq/api/content'),
	'/index.php/apps/portaliq/portal/api',
)
assertEqual(
	'tolerates a trailing slash',
	authBaseFrom('/index.php/apps/portaliq/api/content/'),
	'/index.php/apps/portaliq/portal/api',
)
assertEqual('survives an empty base', authBaseFrom(''), '')

console.log('signInRoutes')

// THE NEGATIVE CASE, first because it is the one that goes wrong.
assertEqual(
	'a public-only portal offers NO sign-in route',
	signInRoutes({ authentication: { modes: ['public'] } }, '/x'),
	[],
)
assertEqual(
	'a portal with no authentication block offers none either',
	signInRoutes({}, '/x'),
	[],
)
assertEqual(
	'a malformed modes value offers none',
	signInRoutes({ authentication: { modes: 'digid' } }, '/x'),
	[],
)
assertEqual(
	'an unknown mode is not turned into a route',
	signInRoutes({ authentication: { modes: ['telepathy'] } }, '/x'),
	[],
)

// THE POSITIVE CASES. Without these every assertion above is satisfied by a
// function that returns [] unconditionally.
assertEqual(
	'digid becomes a labelled OIDC start',
	signInRoutes({ authentication: { modes: ['digid'] } }, '/x'),
	[
		{
			mode: 'digid',
			label: 'Inloggen met DigiD',
			href: '/x/session/oidc/start?provider=digid',
		},
	],
)
assertEqual(
	'nextcloud routes to the nextcloud edge, not to OIDC',
	signInRoutes({ authentication: { modes: ['nextcloud'] } }, '/x'),
	[
		{
			mode: 'nextcloud',
			label: 'Inloggen met uw account',
			href: '/x/session/nextcloud',
		},
	],
)
assertEqual(
	'public is dropped from a MIXED list while the real modes survive',
	signInRoutes(
		{ authentication: { modes: ['public', 'digid', 'eherkenning'] } },
		'/x',
	).map((r) => r.mode),
	['digid', 'eherkenning'],
)

assertEqual(
	'the portal slug travels with the sign-in link, so a shared host cannot resolve the wrong portal',
	signInRoutes({ slug: 'la-franken', authentication: { modes: ['nextcloud', 'digid'] } }, '/x').map((r) => r.href),
	['/x/session/nextcloud?portal=la-franken', '/x/session/oidc/start?provider=digid&portal=la-franken'],
)

// #802: `oidc` is a portal MODE (Google, Microsoft, Keycloak through one
// integration), not a provider the auth edge knows. The edge's providers are
// digid, eherkenning, eidas and generic, so a link carrying `provider=oidc`
// was refused whatever the organisation had configured.
assertEqual(
	'the oidc mode starts the generic provider, which is the one the edge knows',
	signInRoutes({ slug: 'la-franken', authentication: { modes: ['oidc'] } }, '/x').map((r) => r.href),
	['/x/session/oidc/start?provider=generic&portal=la-franken'],
)

console.log('adoptSessionToken')

/**
 * Install a minimal browser window whose URL carries the given fragment.
 *
 * @param {string} hash The location hash, including the leading '#'.
 * @return {{store: Map, replaced: Array}} What the window recorded.
 */
function fakeWindow(hash) {
	const store = new Map()
	const replaced = []
	globalThis.window = {
		location: { hash, pathname: '/apps/portaliq/site', search: '?portal=demo' },
		sessionStorage: {
			getItem: (k) => (store.has(k) ? store.get(k) : null),
			setItem: (k, v) => store.set(k, v),
			removeItem: (k) => store.delete(k),
		},
		history: { replaceState: (_s, _t, url) => replaced.push(url) },
	}
	return { store, replaced }
}

const signedIn = fakeWindow('#token=abc%20123')
assertEqual('a bearer in the fragment is adopted', adoptSessionToken(), 'abc 123')
assertEqual('and the fragment is stripped from the address bar', signedIn.replaced, ['/apps/portaliq/site?portal=demo'])
window.location.hash = ''
assertEqual('a later read returns the stored bearer', adoptSessionToken(), 'abc 123')
clearSessionToken()
assertEqual('signing out forgets it', adoptSessionToken(), '')

fakeWindow('#section-2')
assertEqual('a fragment without a token adopts nothing', adoptSessionToken(), '')
assertEqual('and is no failed sign-in', takeSigninFailed(), false)

// signin-integriq-broker-login REQ-BEL-006: a failed sign-in comes back as
// `#signin=failed`, read once and stripped; the message names no reason.
const failed = fakeWindow('#signin=failed')
assertEqual('a failed sign-in is read from the fragment', takeSigninFailed(), true)
assertEqual('and the fragment is stripped', failed.replaced, ['/apps/portaliq/site?portal=demo'])
assertEqual('the message names no reason', SIGNIN_FAILED_MESSAGE, 'Inloggen is niet gelukt. Probeer het opnieuw of kies een andere manier.')

// site-reaches-portal-parity REQ-SRP-002: a bearer the retired React portal
// left in localStorage `portaliq_token` is taken once, into this tab's store,
// and removed from localStorage, so it never outlives the tab again.
console.log('adoptLegacyToken')
const legacy = fakeWindow('')
const local = new Map([[LEGACY_TOKEN_KEY, 'old-bearer']])
const localStorage = {
	getItem: (k) => (local.has(k) ? local.get(k) : null),
	setItem: (k, v) => local.set(k, v),
	removeItem: (k) => local.delete(k),
}
window.localStorage = localStorage
assertEqual('the old key is portaliq_token', LEGACY_TOKEN_KEY, 'portaliq_token')
assertEqual('a bearer under the old key is adopted', adoptSessionToken(), 'old-bearer')
assertEqual('into this tab\'s store', legacy.store.get('portaliq.session.token'), 'old-bearer')
assertEqual('and removed from localStorage', local.has(LEGACY_TOKEN_KEY), false)
assertEqual('a later read keeps it', adoptSessionToken(), 'old-bearer')
clearSessionToken()
assertEqual('after signing out nothing comes back from the old key', adoptSessionToken(), '')

const both = fakeWindow('#token=fresh')
window.localStorage = localStorage
local.set(LEGACY_TOKEN_KEY, 'old-bearer')
assertEqual('a bearer in the fragment wins over the old key', adoptSessionToken(), 'fresh')
assertEqual('the tab stores the fresh one', both.store.get('portaliq.session.token'), 'fresh')
assertEqual('and the old key is gone all the same', local.has(LEGACY_TOKEN_KEY), false)
window.location.hash = ''
assertEqual('the tab keeps its own bearer', adoptSessionToken(), 'fresh')

fakeWindow('')
window.localStorage = {
	getItem: () => {
		throw new Error('blocked')
	},
}
assertEqual('blocked storage adopts nothing and throws nothing', adoptLegacyToken(), '')
delete globalThis.window

if (failures > 0) {
	console.error(`\n${failures} assertion(s) failed`)
	process.exit(1)
}

console.log('\nall site-auth assertions held')

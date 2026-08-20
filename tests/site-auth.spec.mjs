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

import { authBaseFrom, signInRoutes } from '../src/site/lib/authApi.js'

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

if (failures > 0) {
	console.error(`\n${failures} assertion(s) failed`)
	process.exit(1)
}

console.log('\nall site-auth assertions held')

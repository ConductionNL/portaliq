#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-contribution.spec.mjs — which route a declared action posts to.
//
// Usage:
//   node tests/site-contribution.spec.mjs
//
// THE DEFECT THIS PINS. The renderer posted every action to
// `/portal/api/actions/{appId}/{actionId}`, the endpoint-FORWARD route.
// `ContributionController::action()` matches candidates with
// `authorisedEndpointAction()`, which refuses anything not forwardable — so a
// `type: create` action sent there is refused with or without a session, while
// the collection route accepts it and, for an `anonymous: true` create, accepts
// it with no bearer at all. La Franken advertises exactly that action
// ("Melding indienen — geen account nodig") and the renderer told signed-out
// visitors they had to sign in, for a submission the server has always taken.
//
// Run as a plain node script to match tests/site-auth.spec.mjs — this app has
// no JS test runner.

import {
	actionUrl,
	contributionRoute,
	isAnonymouslySubmittable,
	parseContributionRoute,
} from '../src/site/lib/contributionApi.js'

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

const BASE = '/index.php/apps/portaliq/portal/api'

// La Franken's action, copied from the PUBLIC contract rather than retyped —
// this is the shape `/api/content/contributions?portal=lafranken` serves.
const PUBLIC_INTAKE = {
	id: 'publicIntake',
	type: 'create',
	label: 'Melding indienen',
	fields: ['title', 'status'],
	schema: 'exampleDocument',
	register: 'portaliq',
	anonymous: true,
}

console.log('isAnonymouslySubmittable')
assertEqual(
	"La Franken's public intake is submittable without a session",
	isAnonymouslySubmittable(PUBLIC_INTAKE),
	true,
)
assertEqual(
	'a create action WITHOUT the flag is not',
	isAnonymouslySubmittable({ ...PUBLIC_INTAKE, anonymous: false }),
	false,
)

// THE FLAG ALONE IS NOT ENOUGH, and this is the assertion that says so. The
// server honours `anonymous` only on a create; an endpoint action carrying the
// flag is making a promise no route keeps, and offering a form for it would
// reproduce the original defect with the sign lit the other way.
assertEqual(
	'an ENDPOINT action claiming `anonymous` is still not submittable',
	isAnonymouslySubmittable({ id: 'forward', type: 'endpoint', anonymous: true }),
	false,
)
assertEqual('a missing action is not submittable', isAnonymouslySubmittable(null), false)

console.log('actionUrl')
assertEqual(
	'a create action posts to its COLLECTION, not to the forward route',
	actionUrl({ apiBase: BASE, appId: 'portaliq', action: PUBLIC_INTAKE }),
	`${BASE}/collections/portaliq/exampleDocument`,
)
assertEqual(
	'an endpoint action posts to the forward route',
	actionUrl({
		apiBase: BASE,
		appId: 'openklant',
		action: { id: 'exampleForward', type: 'endpoint' },
	}),
	`${BASE}/actions/openklant/exampleForward`,
)
assertEqual(
	'a trailing slash on the base does not double up',
	actionUrl({ apiBase: `${BASE}/`, appId: 'portaliq', action: PUBLIC_INTAKE }),
	`${BASE}/collections/portaliq/exampleDocument`,
)

// An id from a manifest is authored text, and it lands in a URL path.
assertEqual(
	'ids are encoded on the way into the path',
	actionUrl({
		apiBase: BASE,
		appId: 'a b',
		action: { id: '../../admin', type: 'endpoint' },
	}),
	`${BASE}/actions/a%20b/..%2F..%2Fadmin`,
)

console.log('contributionRoute')
assertEqual(
	'a route round-trips through its parser',
	parseContributionRoute(contributionRoute('portaliq', 'meldingen')),
	{ appId: 'portaliq', pageId: 'meldingen' },
)
assertEqual('an ordinary CMS route is not a contributed one', parseContributionRoute('/begrippen'), null)

if (failures > 0) {
	console.error(`\n${failures} failure(s)`)
	process.exit(1)
}

console.log('\nall assertions passed')

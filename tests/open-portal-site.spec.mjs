#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// open-portal-site.spec.mjs — the "Open portal" row action's URL.
//
// Usage:
//   node tests/open-portal-site.spec.mjs
//
// What is worth asserting here is the ADDRESS, not the click: an instance
// without mod_rewrite needs the `/index.php` prefix (the shape that made the
// task gateway and the notification-mail deeplink 404 on a dev rig), a slug
// carrying a query-unsafe character must arrive encoded, and a row with no
// slug must open nothing at all rather than the site's not-found page.
//
// Run as a plain node script to match tests/site-auth.spec.mjs and
// tests/registry.spec.js — this app has no JS test runner, and adding one for
// two functions would be a bigger change than the thing being tested.
//
// @spec openspec/changes/portals-open-site-action/specs/portaliq-cms/spec.md#requirement-the-portals-overview-must-open-a-portals-public-site

import { createOpenPortalSite, portalSiteUrl } from '../src/lib/openPortalSite.js'

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

/** An instance WITHOUT url rewriting: generateUrl keeps the index.php prefix. */
const withIndexPhp = (path) => `/index.php${path}`
/** An instance WITH url rewriting. */
const pretty = (path) => path

console.log('portalSiteUrl')
assertEqual(
	'keeps the index.php prefix the URL generator produces',
	portalSiteUrl('demo', withIndexPhp),
	'/index.php/apps/portaliq/site?portal=demo',
)
assertEqual(
	'drops it where the instance rewrites URLs',
	portalSiteUrl('demo', pretty),
	'/apps/portaliq/site?portal=demo',
)
assertEqual(
	'encodes a slug that would otherwise split the query',
	portalSiteUrl('gemeente & co', withIndexPhp),
	'/index.php/apps/portaliq/site?portal=gemeente%20%26%20co',
)
assertEqual(
	'encodes a non-ASCII slug',
	portalSiteUrl('münchen', pretty),
	'/apps/portaliq/site?portal=m%C3%BCnchen',
)

console.log('createOpenPortalSite')
{
	const opened = []
	const notified = []
	const handler = createOpenPortalSite({
		generateUrl: withIndexPhp,
		open: (...args) => opened.push(args),
		notify: (message) => notified.push(message),
		translate: (text) => text,
	})

	const returned = handler({ actionId: 'open-site', item: { slug: 'testgemeente' } })
	assertEqual(
		'opens the row slug in a new tab, shielded from the opener',
		opened,
		[['/index.php/apps/portaliq/site?portal=testgemeente', '_blank', 'noopener,noreferrer']],
	)
	assertEqual('returns the URL it opened', returned, '/index.php/apps/portaliq/site?portal=testgemeente')
	assertEqual('says nothing when the row resolves', notified, [])
}

{
	const opened = []
	const notified = []
	const handler = createOpenPortalSite({
		generateUrl: withIndexPhp,
		open: (...args) => opened.push(args),
		notify: (message) => notified.push(message),
		translate: (text) => text,
	})

	for (const item of [{ slug: '' }, { slug: '   ' }, {}, undefined]) {
		handler(item === undefined ? undefined : { item })
	}
	assertEqual('opens nothing for a row without a usable slug', opened, [])
	assertEqual(
		'reports the missing slug once per attempt',
		notified,
		Array(4).fill('This portal has no slug yet, so it has no public address.'),
	)
}

if (failures > 0) {
	console.error(`\n${failures} assertion(s) failed`)
	process.exit(1)
}
console.log('\nall assertions passed')

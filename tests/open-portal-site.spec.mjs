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
// @spec openspec/specs/portaliq-cms/spec.md#requirement-the-portals-overview-must-open-a-portals-public-site

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

	const returned = handler({
		actionId: 'open-site',
		item: { slug: 'testgemeente' },
	})
	assertEqual(
		'opens the row slug in a new tab, shielded from the opener',
		opened,
		[
			[
				'/index.php/apps/portaliq/site?portal=testgemeente',
				'_blank',
				'noopener,noreferrer',
			],
		],
	)
	assertEqual(
		'returns the URL it opened',
		returned,
		'/index.php/apps/portaliq/site?portal=testgemeente',
	)
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

// A slug stored with surrounding whitespace is addressed EXACTLY as stored:
// PortalResolver compares `$site['slug'] === $portalSlug`, so trimming the
// value we send would resolve a different portal than the row names — or
// none. The trim exists for the emptiness test alone, and this pins that.
{
	const opened = []
	const handler = createOpenPortalSite({
		generateUrl: withIndexPhp,
		open: (...args) => opened.push(args),
		notify: () => {},
		translate: (text) => text,
	})

	handler({ item: { slug: ' demo ' } })
	assertEqual(
		'addresses a padded slug exactly as stored, encoded',
		opened.map(([url]) => url),
		['/index.php/apps/portaliq/site?portal=%20demo%20'],
	)
}

// A popup blocker makes window.open return null. Returning the URL anyway
// would report success for a tab that never appeared.
{
	const notified = []
	const handler = createOpenPortalSite({
		generateUrl: withIndexPhp,
		open: () => null,
		notify: (message) => notified.push(message),
		translate: (text) => text,
	})

	const returned = handler({ item: { slug: 'demo' } })
	assertEqual('returns null when the tab was blocked', returned, null)
	assertEqual('tells the administrator the tab was blocked', notified, [
		'The portal site could not be opened. Allow pop-ups for this site and try again.',
	])
}

// The factory is defensively callable with no argument at all.
{
	let threw = null
	try {
		createOpenPortalSite()
	} catch (error) {
		threw = error.message
	}
	assertEqual('createOpenPortalSite() does not throw on a bare call', threw, null)
}

if (failures > 0) {
	console.error(`\n${failures} assertion(s) failed`)
	process.exit(1)
}
console.log('\nall assertions passed')

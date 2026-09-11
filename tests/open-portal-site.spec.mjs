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

/**
 * Record an `open()` call and answer the way the browser answers it.
 *
 * A real `window.open(url, '_blank', 'noopener,noreferrer')` returns NULL for
 * the tab it successfully opened — `noopener` severs the WindowProxy, so the
 * HTML standard returns null (window open steps, "If noopener is true, then
 * return null"). Every mock here returns null for that reason: a mock that
 * answered truthily would certify a handler that reads the return value as
 * success, which is exactly the bug #513 review round 2 found.
 *
 * @param {Array<Array>} log  Where to record the call.
 * @param {...*}         args The arguments the handler passed.
 * @return {null} What the browser returns.
 */
function recordOpen(log, ...args) {
	log.push(args)
	return null
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
		open: (...args) => recordOpen(opened, ...args),
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
		open: (...args) => recordOpen(opened, ...args),
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
		open: (...args) => recordOpen(opened, ...args),
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

// A SUCCESSFUL open returns null, so the return value must not be read as a
// failure signal. Measured in Chromium on 2026-09-11: called inside a click
// with `noopener,noreferrer`, the tab opens (the context reports one new page)
// and the call still returns null; the same call without those features
// returns a WindowProxy. An earlier cut read it anyway and showed "the portal
// site could not be opened" on every open that worked — #513 review round 2.
// These two assertions are that regression, with the opener answering null the
// way the browser does.
{
	const opened = []
	const notified = []
	const handler = createOpenPortalSite({
		generateUrl: withIndexPhp,
		open: (...args) => recordOpen(opened, ...args),
		notify: (message) => notified.push(message),
		translate: (text) => text,
	})

	const returned = handler({ item: { slug: 'demo' } })
	assertEqual(
		'reports the address it opened even though open() answered null',
		returned,
		'/index.php/apps/portaliq/site?portal=demo',
	)
	assertEqual('shows no failure message for a tab that did open', notified, [])
	// The features are asserted HERE too, not only in the happy-path block:
	// this is the block that exercises the null return, so it is the one that
	// must show the shielding is still in place when the browser answers null.
	assertEqual(
		'still shields the tab it could not hand back',
		opened.map(([, target, features]) => [target, features]),
		[['_blank', 'noopener,noreferrer']],
	)
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

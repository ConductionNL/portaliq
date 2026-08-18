#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-regions.spec.mjs — the renderer's half of the region contract.
//
// Usage:
//   node tests/site-regions.spec.mjs
//
// THE SERVER AND THE RENDERER MUST AGREE. `PortalRegionResolver` decides this
// on the way out and `lib/regions.js` decides it again on the way in, because
// a statically built portal never runs the server's copy. Two implementations
// of one rule is a drift risk, so both are pinned to the same three states and
// the region LIST itself is compared against the server's.
//
// Run as a plain node script to match tests/site-auth.spec.mjs.

import { readFileSync } from 'node:fs'
import { DEFAULT_REGIONS, REGIONS, resolveRegions } from '../src/site/lib/regions.js'

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

const PORTAL = { widgetKey: 'portalDefault' }
const PAGE = { widgetKey: 'pageOverride' }

console.log('the three states, per region')
for (const region of REGIONS) {
	const portalRegions = { [region]: [PORTAL] }

	assertEqual(
		`${region}: inherited from the portal`,
		resolveRegions({}, portalRegions)[region],
		[PORTAL],
	)
	assertEqual(
		`${region}: overridden by the page`,
		resolveRegions({ [region]: [PAGE] }, portalRegions)[region],
		[PAGE],
	)
	// THE ONE A NAIVE RESOLVER GETS WRONG. `[]` is falsy, so a truthiness test
	// falls through to the portal's and the page can never blank a region.
	assertEqual(
		`${region}: emptied by the page, NOT inherited`,
		resolveRegions({ [region]: [] }, portalRegions)[region],
		[],
	)
}

console.log('\ndefaults')
// A PORTAL THAT HAS SAID NOTHING GETS TODAY'S SHELL. Every portal that exists
// has no `regions` at all, so this is the path all of them take.
assertEqual(
	'an unconfigured portal still gets a header and a footer',
	Object.entries(resolveRegions({}, {})).map(([k, v]) => [k, v.map((b) => b.widgetKey)]),
	[
		['header', ['brandHeader']],
		['hero', []],
		['main', []],
		['aside', []],
		['footer', ['footerColumns']],
	],
)
assertEqual(
	'a portal CAN blank the shell entirely',
	resolveRegions({}, { header: [], footer: [] }).header,
	[],
)
assertEqual('every region is keyed', Object.keys(resolveRegions({}, {})), REGIONS)
assertEqual('the defaults cover every region', Object.keys(DEFAULT_REGIONS).sort(), [...REGIONS].sort())

console.log('\nagreement with the server')
// THE LISTS ARE COMPARED, not assumed equal. A region added on one side and not
// the other renders as a silently missing area on a live page — the failure
// this whole contract exists to make impossible.
const php = readFileSync('lib/Service/PortalRegionResolver.php', 'utf8')
const declared = /public const REGIONS = \[([^\]]+)\]/.exec(php)
const serverRegions = declared
	? declared[1].split(',').map((s) => s.trim().replace(/^'|'$/g, '')).filter(Boolean)
	: []
assertEqual('the renderer and the server know the same regions', REGIONS, serverRegions)

if (failures > 0) {
	console.error(`\n${failures} failure(s)`)
	process.exit(1)
}

console.log('\nall assertions passed')

#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-grid.spec.mjs — where a page's widgets land.
//
// Usage:
//   node tests/site-grid.spec.mjs
//
// The defect this exists for: `gridY` is absolute over the whole page, but
// full-bleed bands are pulled out of the grid into their own runs. Each run
// then opened a grid that still positioned its cells at their ABSOLUTE rows,
// reserving empty rows for widgets rendered somewhere else. On the La Franken
// landing page that was a 320px void between the hero and the first line of
// text — a page that looks broken while every widget is present, styled and
// correct, which is why no other check noticed.
//
// Run as a plain node script to match tests/site-auth.spec.mjs — this app has
// no JS test runner.

import { cellStyle, runsFor } from '../src/site/lib/gridPlacement.js'

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

const isBand = (key) => key === 'hero'

// The real La Franken landing page: a hero band occupying rows 0-3, then two
// ordinary widgets at rows 4 and 6.
const lafranken = [
	{ id: 'hero', widgetKey: 'hero', gridY: 0, gridHeight: 4, gridWidth: 12 },
	{ id: 'intro', widgetKey: 'markdown', gridY: 4, gridHeight: 2, gridWidth: 12 },
	{
		id: 'diensten',
		widgetKey: 'contributions',
		gridY: 6,
		gridHeight: 4,
		gridWidth: 12,
	},
]

console.log('runsFor')
const runs = runsFor(lafranken, isBand)
assertEqual(
	'splits the band out from the run that follows it',
	runs.map((r) => r.band),
	[true, false],
)
assertEqual(
	'groups the two non-band widgets into ONE run',
	runs[1].widgets.length,
	2,
)

// THE ASSERTION THE BUG FAILED. Without it the run keeps rowOffset 0 and the
// first cell is placed at row 5 of an otherwise empty grid.
assertEqual('re-bases the run on its own first authored row', runs[1].rowOffset, 4)

console.log('runsFor — order')
const interleaved = runsFor(
	[
		{ id: 'a', widgetKey: 'markdown', gridY: 0 },
		{ id: 'hero', widgetKey: 'hero', gridY: 1 },
		{ id: 'b', widgetKey: 'markdown', gridY: 5 },
	],
	isBand,
)
assertEqual(
	'a band splits the page where the author put it',
	interleaved.map((r) => r.band),
	[false, true, false],
)
assertEqual('the first run is based on row 0', interleaved[0].rowOffset, 0)
assertEqual(
	'the run AFTER the band is based on its own row',
	interleaved[2].rowOffset,
	5,
)

console.log('cellStyle')
assertEqual(
	'the first widget of an offset run starts at row 1, not row 5',
	cellStyle(lafranken[1], runs[1].rowOffset).gridRow,
	'1 / span 2',
)
assertEqual(
	'the second keeps its RELATIVE distance from the first',
	cellStyle(lafranken[2], runs[1].rowOffset).gridRow,
	'3 / span 4',
)
assertEqual(
	'no offset means absolute geometry, unchanged',
	cellStyle(lafranken[1]).gridRow,
	'5 / span 2',
)

// Clamping: the manifest validator rejects these at author time, so the render
// path only has to avoid producing nonsense.
assertEqual(
	'an over-wide widget is clamped to the 12th column',
	cellStyle({ gridX: 8, gridWidth: 12, gridHeight: 1 }).gridColumn,
	'9 / span 4',
)
assertEqual(
	'a negative column is clamped to the first',
	cellStyle({ gridX: -3, gridWidth: 4, gridHeight: 1 }).gridColumn,
	'1 / span 4',
)
assertEqual(
	'a missing height is one row, not zero',
	cellStyle({ gridX: 0, gridWidth: 6 }).gridRow,
	'1 / span 1',
)
assertEqual(
	'an offset LARGER than the row does not produce a negative row',
	cellStyle({ gridY: 2, gridHeight: 1 }, 9).gridRow,
	'1 / span 1',
)

if (failures > 0) {
	console.error(`\n${failures} assertion(s) failed`)
	process.exit(1)
}

console.log('\nall site-grid assertions held')

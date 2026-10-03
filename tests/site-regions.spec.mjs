#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-regions.spec.mjs: a page's five regions and how each resolves
// (portal-theme-blocks-and-contributed-pages task 7, REQ-PTB-008, REQ-PTB-009).
//
// Every region resolves page first, then portal, then the default shell, and
// a region is "stated" by its KEY being present. A present empty list is how a
// page clears a region; a truthiness test would read it as "inherit" and the
// portal's hero would come back on the one page that removed it.
//
// Usage:
//   node --test tests/site-regions.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	DEFAULT_REGIONS,
	pageRegionsOf,
	regionOf,
	REGIONS,
	resolveRegions,
} from '../src/site/lib/regions.js'

function PAGE(region) {
	return [{ id: `page-${region}`, widgetKey: 'markdown', slot: region }]
}
const PORTAL = Object.fromEntries(
	REGIONS.map((region) => [
		region,
		[{ id: `portal-${region}`, widgetKey: 'markdown' }],
	]),
)

/**
 * The fifteen cases: each region inherited, overridden and cleared.
 *
 * @param {Function} resolve The resolver under test.
 * @return {Array<string>} The cases that resolved wrongly.
 */
function fifteenCases(resolve) {
	const wrong = []
	for (const region of REGIONS) {
		// Inherited: the page says nothing about this region.
		const inherited = resolve(pageRegionsOf({ widgets: [] }), PORTAL)[region]
		if (inherited[0]?.id !== `portal-${region}`) {
			wrong.push(`${region} inherited`)
		}

		// Overridden: the page fills it.
		const overridden = resolve(pageRegionsOf({ widgets: PAGE(region) }), PORTAL)[
			region
		]
		if (overridden.length !== 1 || overridden[0].id !== `page-${region}`) {
			wrong.push(`${region} overridden`)
		}

		// Cleared: the page names it in clearedRegions.
		const cleared = resolve(
			pageRegionsOf({ widgets: [], clearedRegions: [region] }),
			PORTAL,
		)[region]
		if (cleared.length !== 0) {
			wrong.push(`${region} cleared`)
		}
	}
	return wrong
}

test('each of the five regions resolves inherited, overridden and cleared: fifteen cases', () => {
	assert.deepEqual(fifteenCases(resolveRegions), [])
})

test('a truthiness test in place of a key test fails exactly the cleared cases', () => {
	// The mutation the spec names: `page[region] || portal[region]` in place of
	// Object.hasOwn. It must fail the cleared cases and only those.
	const truthy = (page, portal) =>
		Object.fromEntries(
			REGIONS.map((region) => [
				region,
				page[region]?.length
					? page[region]
					: portal[region] || DEFAULT_REGIONS[region],
			]),
		)
	assert.deepEqual(
		fifteenCases(truthy),
		REGIONS.map((region) => `${region} cleared`),
	)
})

test('a page inherits the portal header, and with no portal regions the default shell renders', () => {
	const portalHeader = {
		header: [{ id: 'portal-header', widgetKey: 'brandHeader', props: {} }],
	}
	assert.equal(
		resolveRegions(pageRegionsOf({ widgets: [] }), portalHeader).header[0].id,
		'portal-header',
	)

	const shell = resolveRegions({}, {})
	assert.deepEqual(
		shell.header.map((b) => b.widgetKey),
		['brandHeader'],
	)
	assert.deepEqual(
		shell.footer.map((b) => b.widgetKey),
		['footerColumns'],
	)
	assert.deepEqual([shell.hero, shell.main, shell.aside], [[], [], []])
	// An array from a cached empty PHP map reads as "the portal says nothing".
	assert.deepEqual(
		resolveRegions({}, []).header.map((b) => b.widgetKey),
		['brandHeader'],
	)
})

test("a page replaces one region: exactly one hero, and it is the page's", () => {
	const portal = {
		hero: [
			{ id: 'portal-hero', widgetKey: 'hero', props: { title: 'Portaal' } },
		],
	}
	const body = {
		widgets: [
			{
				id: 'landing-hero',
				widgetKey: 'hero',
				slot: 'hero',
				props: { title: 'Welkom' },
			},
			{ id: 'intro', widgetKey: 'markdown', slot: 'body' },
		],
	}
	const regions = resolveRegions(pageRegionsOf(body), portal)
	assert.deepEqual(
		regions.hero.map((b) => b.id),
		['landing-hero'],
	)
	assert.deepEqual(
		regions.main.map((b) => b.id),
		['intro'],
	)
})

test("a page clears a region, and every other page still shows the portal's", () => {
	const portal = { hero: [{ id: 'portal-hero', widgetKey: 'hero' }] }
	assert.deepEqual(
		resolveRegions(
			pageRegionsOf({ type: 'grid', widgets: [], clearedRegions: ['hero'] }),
			portal,
		).hero,
		[],
	)
	assert.equal(
		resolveRegions(pageRegionsOf({ type: 'grid', widgets: [] }), portal).hero[0]
			.id,
		'portal-hero',
	)
	// A markdown page can clear a region too.
	assert.deepEqual(
		resolveRegions(
			pageRegionsOf({
				type: 'markdown',
				markdown: 'x',
				clearedRegions: ['hero'],
			}),
			portal,
		).hero,
		[],
	)
})

test('existing pages need no migration: slot body and an empty slot mean main', () => {
	assert.equal(regionOf('body'), 'main')
	assert.equal(regionOf(''), 'main')
	assert.equal(regionOf(undefined), 'main')
	assert.equal(regionOf('heder'), null)
	const regions = pageRegionsOf({
		widgets: [
			{ id: 'a', slot: 'body' },
			{ id: 'b' },
			{ id: 'c', slot: 'heder' },
		],
	})
	assert.deepEqual(Object.keys(regions), ['main'])
	assert.deepEqual(
		regions.main.map((w) => w.id),
		['a', 'b'],
	)
})

test('the served body.regions wins over grouping the flat list', () => {
	const body = {
		widgets: [{ id: 'flat', slot: 'hero' }],
		regions: { main: [{ id: 'served' }] },
		clearedRegions: ['aside'],
	}
	assert.deepEqual(pageRegionsOf(body), { main: [{ id: 'served' }], aside: [] })
})

test('the renderer and the content contract name the same five regions', async () => {
	const { readFileSync } = await import('node:fs')
	const php = readFileSync(
		new URL('../lib/Service/PortalRegionResolver.php', import.meta.url),
		'utf8',
	)
	const listed = php
		.match(/REGIONS = \[([^\]]*)\]/)[1]
		.match(/'([a-z]+)'/g)
		.map((q) => q.slice(1, -1))
	assert.deepEqual(listed, REGIONS)
})

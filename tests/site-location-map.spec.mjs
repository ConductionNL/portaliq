#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-location-map.spec.mjs: the location map block (portaliq/LocatiesKaart):
// the organisation's places as a numbered list beside a sketch map with the
// same numbers, chips per kind of place, and a route link per place. The
// sketch draws from the authored coordinates and loads nothing from another
// site; the list carries everything, so the map is never the only way in.
//
// Usage:
//   node --test tests/site-location-map.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { SITE_COMPOSITIONS } from '../src/site/widgets/coverage.js'
import { loaders, metas } from '../src/site/widgets/index.js'
import {
	categoryChips,
	MAP_BOX,
	markerPositions,
	normaliseLocations,
	routeHref,
} from '../src/site/widgets/nlLocationMap/locationMap.js'

const read = (name) => readFileSync(new URL(`../${name}`, import.meta.url), 'utf8')

const AUTHORED = [
	{ name: 'Stadskantoor', address: 'Marktplein 1, 3311 AA Zuiddrecht', opening: 'Op afspraak', category: 'Balie en loket', lat: 51.81, lon: 4.67, links: [{ label: 'Afspraak maken', href: '/afspraak-maken' }, { label: 'Kwaad', href: 'javascript:alert(1)' }] },
	{ name: 'Milieustraat', address: 'Industrieweg 40', category: 'Afval', lat: 51.80, lon: 4.70 },
	{ name: 'Bibliotheek', address: 'Havenkade 8', category: 'Bibliotheek en cultuur', lat: 'x' },
	{ name: '   ' },
]

test('locations keep their order and number, drop an empty name and an unsafe link', () => {
	const locations = normaliseLocations(AUTHORED)
	assert.deepEqual(locations.map((l) => [l.number, l.name]), [[1, 'Stadskantoor'], [2, 'Milieustraat'], [3, 'Bibliotheek']])
	assert.deepEqual(locations[0].links, [{ label: 'Afspraak maken', href: '/afspraak-maken' }])
	assert.equal(locations[2].lat, null, 'a coordinate that is not a number is no coordinate')
	assert.deepEqual(normaliseLocations('nope'), [])
})

test('the chips are everything plus each kind, and none for a single kind', () => {
	const locations = normaliseLocations(AUTHORED)
	assert.deepEqual(categoryChips(locations, 'Alle locaties').map((c) => c.label), ['Alle locaties', 'Balie en loket', 'Afval', 'Bibliotheek en cultuur'])
	assert.deepEqual(categoryChips(normaliseLocations([AUTHORED[0]]), 'Alle locaties'), [])
})

test('markers sit inside the map box, north up, and an unlocated place has none', () => {
	const markers = markerPositions(normaliseLocations(AUTHORED))
	assert.deepEqual(markers.map((m) => m.number), [1, 2])
	for (const marker of markers) {
		assert.ok(marker.x >= MAP_BOX.margin && marker.x <= MAP_BOX.width - MAP_BOX.margin, `x ${marker.x}`)
		assert.ok(marker.y >= MAP_BOX.margin && marker.y <= MAP_BOX.height - MAP_BOX.margin, `y ${marker.y}`)
	}
	assert.ok(markers[0].y < markers[1].y, 'the northern place is drawn higher')
	assert.ok(markers[0].x < markers[1].x, 'the western place is drawn to the left')
	assert.deepEqual(markerPositions(normaliseLocations([AUTHORED[0]])), [{ number: 1, x: MAP_BOX.width / 2, y: MAP_BOX.height / 2 }])
})

test('the route goes to the coordinates, else the address, else nowhere', () => {
	const [kantoor, , bieb] = normaliseLocations(AUTHORED)
	assert.equal(routeHref(kantoor), 'https://www.openstreetmap.org/directions?to=51.81%2C4.67')
	assert.equal(routeHref(bieb), 'https://www.openstreetmap.org/search?query=Havenkade%208')
	assert.equal(routeHref({ lat: null, lon: null, address: '' }), '')
})

test('the block is registered, described and recorded as a composition', () => {
	assert.ok(Object.hasOwn(loaders, 'nlLocationMap'), 'no loader')
	assert.equal(metas.nlLocationMap.key, 'nlLocationMap')
	assert.ok(SITE_COMPOSITIONS.some((entry) => entry.key === 'nlLocationMap'))
	const source = read('src/site/widgets/nlLocationMap/NlLocationMap.vue')
	assert.doesNotMatch(source, /https?:\/\/(?!www\.openstreetmap\.org)[a-z]/i, 'the block loads nothing from another site')
	assert.match(source, /role="img"/, 'the sketch is one labelled image')
	assert.match(source, /aria-pressed/, 'a chip says whether it is chosen')
})

test('the Zuiddrecht example has the Locaties page of the board', () => {
	const site = JSON.parse(read('lib/Settings/sites/zuiddrecht.json'))
	const page = site.pages.find((p) => p.route === '/contact/locaties')
	assert.ok(page, 'no /contact/locaties page')
	const block = page.body.widgets.find((w) => w.widgetKey === 'nlLocationMap')
	assert.ok(block, 'the page has no location map')
	assert.equal(normaliseLocations(block.props.locations).length, 3)
	assert.equal(markerPositions(normaliseLocations(block.props.locations)).length, 3)
})

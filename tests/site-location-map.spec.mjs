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
	markerLabels,
	markerPositions,
	normaliseHours,
	normaliseLocations,
	openUntil,
	routeHref,
	sketchGround,
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

test('a place link to a site route takes the site address', () => {
	// Seen live on the Zuiddrecht rehearsal (25 Nov 2026): "Afspraak maken"
	// read a bare /afspraak-maken under the app path and left the site.
	// @spec openspec/specs/site-navigation/spec.md#requirement-a-block-link-to-a-site-page-carries-the-sites-own-address
	const source = read('src/site/widgets/nlLocationMap/NlLocationMap.vue')
	assert.match(source, /:href="addressFor\(link\.href\)"/)
	assert.doesNotMatch(source, /:href="link\.href"/)
	assert.match(source, /linkAddress\(href, \(route\) =>\s*addressOfRoute\(/)
})

// T04: the gaps the live check found next to LocatiesKaart.
// @spec openspec/changes/archive/2026-11-26-site-location-map/tasks.md#t04

test('opening hours per day read Dutch and English keys and drop what cannot be read', () => {
	const hours = normaliseHours({ ma: '8.30-17.00', Tue: '09:00 – 12:00, 13:00-17:00', za: 'gesloten', xx: '9-17', wo: '17.00-9.00', do: 9 })
	assert.deepEqual(hours, {
		1: [{ open: 510, close: 1020 }],
		2: [{ open: 540, close: 720 }, { open: 780, close: 1020 }],
	})
	assert.deepEqual(normaliseHours(null), {})
	assert.deepEqual(normaliseHours(['ma']), {})
	assert.deepEqual(normaliseLocations([{ name: 'Kantoor' }])[0].hours, {}, 'no hours authored is no hours')
})

test('the open-now line: open until the closing time, closed outside the hours, nothing without hours', () => {
	const hours = normaliseHours({ ma: '8.30-17.00', di: '9.00-12.00, 13.00-17.30' })
	// Monday 23 November 2026, in the visitor's clock.
	assert.equal(openUntil(hours, new Date(2026, 10, 23, 10, 15)), '17:00')
	assert.equal(openUntil(hours, new Date(2026, 10, 23, 8, 29)), false, 'before opening')
	assert.equal(openUntil(hours, new Date(2026, 10, 23, 17, 0)), false, 'the closing minute is closed')
	assert.equal(openUntil(hours, new Date(2026, 10, 24, 12, 30)), false, 'the lunch break')
	assert.equal(openUntil(hours, new Date(2026, 10, 24, 13, 0)), '17:30')
	assert.equal(openUntil(hours, new Date(2026, 10, 25, 10, 0)), false, 'a day without hours is closed')
	assert.equal(openUntil({}, new Date(2026, 10, 23, 10, 0)), null)
})

test('each marker carries its place name, on the side that keeps it inside the map', () => {
	const locations = normaliseLocations(AUTHORED)
	const labels = markerLabels(markerPositions(locations), locations)
	assert.deepEqual(labels.map((l) => [l.number, l.name, l.anchor]), [[1, 'Stadskantoor', 'start'], [2, 'Milieustraat', 'end']])
	for (const label of labels) {
		assert.ok(label.x > 0 && label.x < MAP_BOX.width, `label x ${label.x}`)
	}
})

test('the sketch draws streets, water and a park inside its box', () => {
	const ground = sketchGround()
	assert.ok(ground.streets.length >= 3, 'streets')
	assert.match(ground.water, /^M0 [\d.]+ C .* Z$/)
	assert.ok(ground.park.x + ground.park.width <= MAP_BOX.width && ground.park.y + ground.park.height <= MAP_BOX.height)
})

test('the block draws the board: caption, name labels, a card with an open-now line, Route as an outlined button with an icon', () => {
	const source = read('src/site/widgets/nlLocationMap/NlLocationMap.vue')
	assert.match(source, /'Kaart: schets, geen echte kaartgegevens'/)
	assert.match(source, /class="nl-location-map__caption"/)
	assert.match(source, /class="nl-location-map__marker-name"/)
	assert.match(source, /'Nu open tot \{time\} uur'/)
	assert.match(source, /utrecht-button-link--secondary-action nl-location-map__route/)
	assert.match(source, /class="nl-location-map__route-icon"[\s\S]*?aria-hidden="true"/)
	assert.match(source, /\.nl-location-map__place \{[^}]*border: 1px solid/)
	assert.match(source, /now: null/, 'no clock before the browser runs the block')
})

test('the Zuiddrecht places carry hours that match their opening text', () => {
	const site = JSON.parse(read('lib/Settings/sites/zuiddrecht.json'))
	const page = site.pages.find((p) => p.route === '/contact/locaties')
	const block = page.body.widgets.find((w) => w.widgetKey === 'nlLocationMap')
	const [kantoor, straat, bieb] = normaliseLocations(block.props.locations)
	assert.equal(openUntil(kantoor.hours, new Date(2026, 10, 23, 9, 0)), '17:00')
	assert.equal(openUntil(straat.hours, new Date(2026, 10, 28, 10, 0)), '15:00', 'Saturday')
	assert.equal(openUntil(bieb.hours, new Date(2026, 10, 23, 11, 0)), false, 'the library is closed on Monday')
})

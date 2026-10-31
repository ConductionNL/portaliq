#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// traffic-board-cards.spec.mjs: the Traffic page lays its cards out the way
// the portaliq/PtVerkeer board draws them. Below the headline numbers and
// "Per dag" the cards run in two columns, and the missing pages and the site
// searches are cards of their own ("Ontbrekende pagina's", "Zoeken op de
// site") instead of sub-tables under Pages and Sources. TrafficPages and
// TrafficSources pick the part they draw from `content.part`.
//
// Usage:
//   node --test tests/traffic-board-cards.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const read = (name) => readFileSync(new URL(`../${name}`, import.meta.url), 'utf8')
const MANIFEST = JSON.parse(read('src/manifest.json'))
const NL = JSON.parse(read('l10n/nl.json')).translations
const TRAFFIC = MANIFEST.pages.find((page) => page.id === 'Traffic').config
const widget = (id) => TRAFFIC.widgets.find((w) => w.id === id)
const cell = (id) => TRAFFIC.layout.find((entry) => entry.widgetId === id)

test('the missing pages and the site searches are cards of their own', () => {
	assert.equal(widget('traffic-missing-pages')?.type, 'TrafficPages')
	assert.equal(widget('traffic-missing-pages').content.part, 'missing')
	assert.equal(widget('traffic-searches')?.type, 'TrafficSources')
	assert.equal(widget('traffic-searches').content.part, 'searches')
	assert.equal(widget('traffic-pages').content.part, 'pages')
	assert.equal(widget('traffic-sources').content.part, 'channels')
	assert.equal(NL[widget('traffic-missing-pages').title], "Ontbrekende pagina's")
	assert.equal(NL[widget('traffic-searches').title], 'Zoeken op de site')
	assert.equal(NL[widget('traffic-recordings').title], 'Sessie-opnames')
})

test('the cards run in two columns, in the order of the board', () => {
	const rows = [
		['traffic-pages', 'traffic-sources'],
		['traffic-missing-pages', 'traffic-searches'],
		['traffic-heatmap', 'traffic-recordings'],
		['traffic-dimensions', 'traffic-errors'],
		['traffic-visitors', 'traffic-goals'],
	]
	let previous = cell('traffic-daily').gridY
	for (const [left, right] of rows) {
		assert.ok(cell(left) && cell(right), `${left} or ${right} is not placed`)
		assert.deepEqual([cell(left).gridX, cell(left).gridWidth], [0, 6], `${left} is not the left half`)
		assert.deepEqual([cell(right).gridX, cell(right).gridWidth], [6, 6], `${right} is not the right half`)
		assert.equal(cell(left).gridY, cell(right).gridY, `${left} and ${right} are not side by side`)
		assert.ok(cell(left).gridY > previous, `${left} is above the row before it`)
		previous = cell(left).gridY
	}
	assert.equal(cell('traffic-paths').gridWidth, 12)
	assert.ok(cell('traffic-paths').gridY > cell('traffic-searches').gridY)
	assert.ok(cell('traffic-paths').gridY < cell('traffic-heatmap').gridY)
})

test('no two cards overlap', () => {
	const taken = new Map()
	for (const entry of TRAFFIC.layout) {
		for (let y = entry.gridY; y < entry.gridY + entry.gridHeight; y++) {
			for (let x = entry.gridX; x < entry.gridX + entry.gridWidth; x++) {
				const key = `${x},${y}`
				assert.ok(!taken.has(key), `${entry.widgetId} overlaps ${taken.get(key)}`)
				taken.set(key, entry.widgetId)
			}
		}
	}
	const placed = new Set(TRAFFIC.layout.map((entry) => entry.widgetId))
	for (const w of TRAFFIC.widgets) {
		assert.ok(placed.has(w.id), `${w.id} is declared but not placed`)
	}
})

test('the widgets draw only the part they are given', () => {
	const pages = read('src/widgets/TrafficPages.vue')
	assert.match(pages, /\['pages', 'missing'\]\.includes\(this\.content\?\.part\)/)
	assert.match(pages, /v-if="emptyState === '' && showPages"/)
	assert.match(pages, /v-if="emptyState === '' && showMissing"/)
	const sources = read('src/widgets/TrafficSources.vue')
	assert.match(sources, /\['channels', 'searches'\]\.includes\(this\.content\?\.part\)/)
	assert.match(sources, /v-if="emptyState === '' && part !== 'searches'"/)
	assert.match(sources, /v-if="emptyState === '' && showSearches"/)
	for (const text of [
		'No missing page requested in this period.',
		'Addresses where visitors looked for a page that does not exist. Make the page or a redirect.',
		'No search recorded in this period.',
		'What visitors typed into the search box of the portal. Counted per term, not per person.',
	]) {
		assert.ok(NL[text], `"${text}" is not in the Dutch catalogue`)
	}
})

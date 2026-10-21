#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// availability-report.spec.mjs: the Availability report (operate-availability-
// report) is manifest config in the shape of the portaliq/PtBeschikbaarheid
// board. The portal is a page filter over the published portals (by slug);
// three KPI cards read the report's summary; the months and the outages are
// tables over the same admin endpoint; "How we measure" is a note; Download
// sits in the header. Every card follows the chosen portal through
// `@page.portal`. The page is reachable from the Reports hub.
//
// Usage:
//   node --test tests/availability-report.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const read = (name) => readFileSync(new URL(`../${name}`, import.meta.url), 'utf8')
const MANIFEST = JSON.parse(read('src/manifest.json'))
const NL = JSON.parse(read('l10n/nl.json')).translations
const ROUTES = read('appinfo/routes.php')
const PAGE = MANIFEST.pages.find((page) => page.id === 'Availability')
const CONFIG = PAGE.config
const widget = (id) => CONFIG.widgets.find((w) => w.id === id)
const cell = (id) => CONFIG.layout.find((entry) => entry.widgetId === id)
const REPORT = '/apps/portaliq/api/availability/@page.portal'

test('the Reports hub offers the report', () => {
	const hub = MANIFEST.pages.find((page) => page.id === 'Reports')
	const card = hub.config.cards.find((candidate) => candidate.id === 'Availability')
	assert.equal(card.route, 'Availability')
	assert.equal(PAGE.type, 'dashboard')
	assert.match(ROUTES, /'url' => '\/api\/availability\/\{portal\}'/)
	assert.match(ROUTES, /'url' => '\/api\/availability\/\{portal\}\/export'/)
})

test('the portal is a page filter over the published portals, by slug', () => {
	const [filter] = CONFIG.pageFilters
	assert.equal(filter.key, 'portal')
	assert.equal(NL[filter.label], 'Portal')
	assert.deepEqual(filter.optionsFrom, {
		register: 'portaliq',
		schema: 'portal',
		filter: { status: 'published' },
		valueField: 'slug',
		labelField: 'title',
	})
})

test('three KPI cards read the summary of the chosen portal', () => {
	const expected = [
		['availability-twelve-months', 'summary.percentage', 'Twaalf maanden'],
		['availability-outage-count', 'summary.outageCount', 'Storingen'],
		['availability-longest', 'summary.longestMinutes', 'Langste storing'],
	]
	expected.forEach(([id, field, dutch], index) => {
		const kpi = widget(id)
		assert.equal(kpi?.type, 'stat', `${id} is not a stat card`)
		assert.equal(kpi.content.endpointSource.url, REPORT)
		assert.equal(kpi.content.valueField, field)
		assert.equal(NL[kpi.content.label], dutch)
		assert.ok(NL[kpi.content.caption], `the caption of ${id} is not in the Dutch catalogue`)
		assert.deepEqual([cell(id).gridX, cell(id).gridY, cell(id).gridWidth], [index * 4, 0, 4])
	})
})

test('the months and the outages are tables side by side, the note under the outages', () => {
	const months = widget('availability-months')
	assert.equal(months.type, 'table')
	assert.equal(months.content.endpointSource.url, REPORT)
	assert.equal(months.content.endpointSource.responsePath, 'months')
	assert.deepEqual(months.content.columns.map((c) => c.key), ['month', 'percentage'])
	assert.equal(NL[months.title], 'Beschikbaarheid per maand')

	const outages = widget('availability-outages')
	assert.equal(outages.type, 'table')
	assert.equal(outages.content.endpointSource.responsePath, 'outages')
	assert.deepEqual(outages.content.columns.map((c) => c.key), ['startedAt', 'endedAt', 'durationMinutes', 'cause'])
	const cause = outages.content.columns.find((c) => c.key === 'cause')
	for (const code of cause.enum) {
		assert.ok(NL[cause.enumLabels[code]], `the cause "${code}" has no Dutch label`)
	}
	assert.deepEqual(
		outages.content.columns.map((c) => NL[c.label]),
		['Begon', 'Eindigde', 'Duur', 'Oorzaak'],
	)

	const how = widget('availability-how')
	assert.equal(how.type, 'banner')
	assert.equal(NL[how.title], 'Zo meten wij')
	assert.ok(NL[how.content.text], 'the note is not in the Dutch catalogue')

	assert.deepEqual([cell('availability-months').gridX, cell('availability-months').gridWidth], [0, 6])
	assert.deepEqual([cell('availability-outages').gridX, cell('availability-outages').gridWidth], [6, 6])
	assert.equal(cell('availability-months').gridY, cell('availability-outages').gridY)
	assert.equal(cell('availability-how').gridX, 6)
	assert.equal(
		cell('availability-how').gridY,
		cell('availability-outages').gridY + cell('availability-outages').gridHeight,
	)
})

test('Download sits in the header and downloads the chosen portal', () => {
	const [download] = CONFIG.headerActions
	assert.equal(download.type, 'api-call')
	assert.equal(download.url, `${REPORT}/export`)
	assert.equal(download.method, 'POST')
	assert.equal(download.download, true)
	assert.match(ROUTES, /'name' => 'availability#export', 'url' => '\/api\/availability\/\{portal\}\/export', 'verb' => 'POST'/)
	assert.equal(NL[download.label], 'Downloaden')
	assert.ok(!CONFIG.widgets.some((w) => w.type === 'AvailabilityReport'), 'the old one-widget report is still placed')
	assert.doesNotMatch(read('src/registry.js'), /AvailabilityReport/)
})

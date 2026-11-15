#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// dashboard-site-wide-figures.spec.mjs: the dashboard carries the figures of
// the website and Mijn Zuiddrecht together that the portaliq/PtDashboard
// board draws: KPIs "Bezoekers, laatste 7 dagen" and "Beschikbaarheid", a
// card "Beschikbaarheid" per portal for last month, a card "Verkeer, laatste
// 7 dagen" with four figures and the most viewed pages. Every figure reads the
// admin overview routes, so the paths it plucks must be keys those routes
// answer. The KPI row holds the board's four figures, a quarter of the row each.
//
// Usage:
//   node --test tests/dashboard-site-wide-figures.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const read = (name) => readFileSync(new URL(`../${name}`, import.meta.url), 'utf8')
const MANIFEST = JSON.parse(read('src/manifest.json'))
const NL = JSON.parse(read('l10n/nl.json')).translations
const ROUTES = read('appinfo/routes.php')
const OVERVIEW = read('lib/Service/Overview/PortalOverview.php')
const DASHBOARD = MANIFEST.pages.find((page) => page.id === 'Dashboard').config
const widget = (id) => DASHBOARD.widgets.find((w) => w.id === id)
const cell = (id) => DASHBOARD.layout.find((entry) => entry.widgetId === id)
const TRAFFIC = '/apps/portaliq/api/overview/traffic'
const AVAILABILITY = '/apps/portaliq/api/overview/availability'

/**
 * Whether the overview service answers a top-level key.
 *
 * @param {string} key The key.
 * @return {boolean}
 */
const answers = (key) => new RegExp(`'${key}' =>`).test(OVERVIEW)

test('the overview routes answer at the admin controller', () => {
	assert.match(ROUTES, /'portalOverview#traffic', 'url' => '\/api\/overview\/traffic', 'verb' => 'GET'/)
	assert.match(ROUTES, /'portalOverview#availability', 'url' => '\/api\/overview\/availability', 'verb' => 'GET'/)
})

test('the KPI row holds the board\'s four figures, opening with visitors and availability', () => {
	assert.deepEqual(DASHBOARD.kpiRow, ['kpi-visitors', 'kpi-availability', 'kpi-requests', 'kpi-submissions'])
	const visitors = widget('kpi-visitors').content.source
	assert.deepEqual(visitors, { kind: 'endpoint', url: TRAFFIC, params: { days: '7' }, path: 'visitors' })
	const availability = widget('kpi-availability').content.source
	assert.deepEqual(availability, { kind: 'endpoint', url: AVAILABILITY, path: 'percentage' })
	for (const key of ['visitors', 'percentage']) assert.ok(answers(key), key)
	DASHBOARD.kpiRow.forEach((id, index) => {
		assert.deepEqual([cell(id).gridX, cell(id).gridY, cell(id).gridWidth], [index * 3, 0, 3], id)
	})
})

test('the availability card lists each portal of last month', () => {
	const card = widget('overview-availability')
	assert.equal(card.type, 'record-list')
	assert.deepEqual(card.content.endpointSource, { url: AVAILABILITY, responsePath: 'portals' })
	assert.equal(card.content.title, '{title}')
	assert.equal(card.content.trailing, '{percentage}%')
	assert.ok(answers('portals') && answers('title'))
	assert.deepEqual(card.headerLink, { label: 'Rapport over 12 maanden', route: 'Availability' })
})

test('the traffic card holds four figures and the most viewed pages', () => {
	const figures = widget('overview-traffic')
	assert.equal(figures.type, 'stats-block')
	assert.equal(figures.content.layout, 'grid')
	assert.deepEqual(figures.content.stats.map((s) => s.source.path), ['pageViews', 'sessions', 'visitors', 'engagedShare'])
	for (const stat of figures.content.stats) {
		assert.deepEqual(stat.source, { kind: 'endpoint', url: TRAFFIC, params: { days: '7' }, path: stat.source.path })
		assert.ok(answers(stat.source.path), stat.source.path)
		assert.ok(NL[stat.label], `"${stat.label}" is not in the Dutch catalogue`)
	}
	const pages = widget('overview-top-pages')
	assert.equal(pages.type, 'record-list')
	assert.deepEqual(pages.content.endpointSource, { url: TRAFFIC, params: { days: '7' }, responsePath: 'pages' })
	assert.equal(pages.content.title, '{path}')
	assert.equal(pages.content.trailing, '{views}')
	assert.deepEqual(pages.headerLink, { label: 'Naar verkeer', route: 'Traffic' })
})

test('the right column reads availability, traffic, pages, then Mijn Zuiddrecht nu', () => {
	const column = ['overview-availability', 'overview-traffic', 'overview-top-pages', 'now-mijn'].map(cell)
	for (const entry of column) assert.deepEqual([entry.gridX, entry.gridWidth], [8, 4], entry.widgetId)
	for (let i = 1; i < column.length; i++) {
		assert.equal(column[i].gridY, column[i - 1].gridY + column[i - 1].gridHeight, column[i].widgetId)
	}
	for (const id of ['overview-availability', 'overview-traffic', 'overview-top-pages']) {
		assert.ok(NL[widget(id).title], `"${widget(id).title}" is not in the Dutch catalogue`)
		assert.ok(NL[widget(id).content.emptyText || widget(id).title])
	}
})

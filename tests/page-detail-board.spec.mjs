#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// page-detail-board.spec.mjs: a page's detail follows the portaliq/
// PtPaginaVerkeer board. The four traffic cards sit in the Verkeer tab above
// the incoming and outgoing flows instead of above the tab strip, and the
// header carries the status pill, a breadcrumb back to the pages and the
// page's path, without the follow button the board does not draw.
//
// Usage:
//   node --test tests/page-detail-board.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const read = (name) => readFileSync(new URL(`../${name}`, import.meta.url), 'utf8')
const MANIFEST = JSON.parse(read('src/manifest.json'))
const NL = JSON.parse(read('l10n/nl.json')).translations
const CONFIG = MANIFEST.pages.find((page) => page.id === 'PageDetail').config
const TABS = CONFIG.widgets.find((w) => w.id === 'page-tabs').content.tabs
const KPIS = ['page-kpi-page-views', 'page-kpi-sessions', 'page-kpi-visitors', 'page-kpi-engaged']

test('the traffic cards sit in the Verkeer tab, above the flows', () => {
	const traffic = TABS.find((tab) => tab.label === 'Verkeer')
	assert.deepEqual(traffic.widgetIds, [...KPIS, 'page-traffic-incoming', 'page-traffic-outgoing'])
	assert.equal(traffic.columns, 4)
	assert.deepEqual(traffic.layout.map((cell) => cell.gridWidth), [3, 3, 3, 3, 6, 6])
	assert.deepEqual(CONFIG.layout.map((cell) => cell.widgetId), ['page-tabs'], 'a card is drawn above the tabs as well')
	assert.equal(CONFIG.layout[0].gridY, 0)
})

test('the header carries status, breadcrumb and path, no follow button', () => {
	assert.equal(CONFIG.follow, false)
	assert.equal(CONFIG.favourite, false)
	assert.equal(CONFIG.statusPill.field, 'status')
	assert.equal(NL[CONFIG.statusPill.labels.published], 'Gepubliceerd')
	assert.equal(NL[CONFIG.statusPill.labels.draft], 'Concept')
	assert.deepEqual(CONFIG.breadcrumb, { label: 'Pages', route: 'Pages', currentField: 'title' })
	assert.equal(NL[CONFIG.breadcrumb.label], "Pagina's")
	assert.equal(CONFIG.headerMeta, '{route}')
})

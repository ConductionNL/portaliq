#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// portal-detail-tabs.spec.mjs: the Portal page's tab strip lists what one
// portal is made of in the order the portaliq/PtPortal board draws it:
// Overzicht, Pagina's, Menu's, Media, Nieuws, Mededelingen, Begrippen,
// Instellingen, Historie.
//
// Each child tab is an object-list over a schema of this register, filtered
// on the portal's SLUG (`@object.slug`): children name their portal by slug,
// and a filter on a property the rows do not carry matches nothing and says
// nothing. So the test also asserts the filtered property exists.
//
// Usage:
//   node --test tests/portal-detail-tabs.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const read = (name) => readFileSync(new URL(`../${name}`, import.meta.url), 'utf8')
const MANIFEST = JSON.parse(read('src/manifest.json'))
const SCHEMAS = JSON.parse(read('lib/Settings/portaliq_register.json')).components.schemas
const NL = JSON.parse(read('l10n/nl.json')).translations
const DETAIL = MANIFEST.pages.find((page) => page.id === 'PortalDetail').config
const widget = (id) => DETAIL.widgets.find((w) => w.id === id)
const TABS = widget('portal-tabs').content.tabs
const PAGE_IDS = new Set(MANIFEST.pages.map((page) => page.id))

test('the tabs follow the board order', () => {
	assert.deepEqual(TABS.map((tab) => tab.label), [
		'Overzicht',
		'Pagina\'s',
		'Menu\'s',
		'Media',
		'Nieuws',
		'Mededelingen',
		'Begrippen',
		'Instellingen',
		'Historie',
	])
})

for (const [label, schema] of [['Media', 'media'], ['Nieuws', 'newsItem'], ['Mededelingen', 'portalNotice']]) {
	test(`the ${label} tab lists this portal's ${schema} records and counts them`, () => {
		const tab = TABS.find((entry) => entry.label === label)
		assert.ok(tab, `no ${label} tab`)
		const list = widget(tab.widgetId)
		assert.ok(list, `the ${label} tab names a widget that is not declared`)
		assert.equal(list.type, 'object-list')
		assert.equal(list.content.register, 'portaliq')
		assert.equal(list.content.schema, schema)
		assert.deepEqual(list.content.filter, { portal: '@object.slug' })
		assert.ok(SCHEMAS[schema].properties.portal, `${schema} has no portal property to filter on`)
		for (const column of list.content.columns) {
			assert.ok(SCHEMAS[schema].properties[column.key], `${schema} has no "${column.key}"`)
		}
		assert.deepEqual(tab.countFrom, { register: 'portaliq', schema, filter: { portal: '@object.slug' } })
		if (list.content.rowRoute) {
			assert.ok(PAGE_IDS.has(list.content.rowRoute), `row route ${list.content.rowRoute} is not a page`)
		}
		if (list.content.viewAllRoute) {
			assert.ok(PAGE_IDS.has(list.content.viewAllRoute), `view-all route ${list.content.viewAllRoute} is not a page`)
		}
		assert.ok(NL[list.content.emptyText], `"${list.content.emptyText}" is not in the Dutch catalogue`)
		for (const column of list.content.columns) {
			assert.ok(NL[column.label], `column "${column.label}" is not in the Dutch catalogue`)
		}
	})
}

test('every child tab widget is declared once and kept out of the page layout', () => {
	const layoutIds = new Set((DETAIL.layout || []).map((entry) => entry.widgetId))
	for (const tab of TABS.filter((entry) => entry.widgetId)) {
		assert.equal(DETAIL.widgets.filter((w) => w.id === tab.widgetId).length, 1, tab.widgetId)
		assert.equal(layoutIds.has(tab.widgetId), false, `${tab.widgetId} is drawn twice`)
	}
})

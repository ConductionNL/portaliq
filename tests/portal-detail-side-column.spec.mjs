#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// portal-detail-side-column.spec.mjs: the portal page carries its facts in a
// side column, as the portaliq/PtPortal and PtPortalInstellingen boards draw
// it. "Domein en certificaat", "Thema", "Status en laatste publicatie" and
// "Beheerders" sit beside the content tabs (Overzicht up to Begrippen), not
// inside Overzicht; "Verkeer meten" sits beside Instellingen and writes
// `traffic.enabled` and `traffic.regionGranularity`. The Pagina's tab shows
// the board's columns: title with its path under it, status, last change.
//
// Usage:
//   node --test tests/portal-detail-side-column.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const read = (name) => readFileSync(new URL(`../${name}`, import.meta.url), 'utf8')
const MANIFEST = JSON.parse(read('src/manifest.json'))
const NL = JSON.parse(read('l10n/nl.json')).translations
const PORTAL = JSON.parse(read('lib/Settings/portaliq_register.json')).components.schemas.portal.properties
const CONFIG = MANIFEST.pages.find((page) => page.id === 'PortalDetail').config
const widget = (id) => CONFIG.widgets.find((w) => w.id === id)
const TABS = widget('portal-tabs').content.tabs
const tabId = (tab) => tab.id || tab.widgetId
const SUMMARY = ['portal-side-domain', 'portal-side-theme', 'portal-side-status', 'portal-side-editors']

test('the summary cards are the side column beside the content tabs', () => {
	assert.deepEqual(CONFIG.sideColumn.slice(0, 4), SUMMARY)
	const content = TABS.map(tabId).filter((id) => !['portal-settings', 'portal-history'].includes(id))
	assert.equal(content[0], 'overview', 'the Overzicht tab has no stable id for the side column to name')
	for (const id of SUMMARY) {
		assert.deepEqual(widget(id).tabs, content, `${id} does not sit beside the content tabs`)
	}
	const overview = TABS.find((tab) => tab.id === 'overview')
	for (const id of SUMMARY) {
		assert.ok(!overview.widgetIds.includes(id), `${id} is drawn in Overzicht and in the side column`)
	}
})

test('Verkeer meten sits beside Instellingen and writes the traffic settings', () => {
	const card = widget('portal-side-traffic')
	assert.ok(CONFIG.sideColumn.includes('portal-side-traffic'))
	assert.equal(card.type, 'settings-form')
	assert.deepEqual(card.tabs, ['portal-settings'])
	assert.equal(NL[card.title], 'Verkeer meten')
	const keys = card.content.fields.map((field) => field.key)
	assert.deepEqual(keys, ['traffic.enabled', 'traffic.regionGranularity'])
	const traffic = PORTAL.traffic.properties
	assert.equal(traffic.enabled.type, 'boolean')
	const region = card.content.fields[1]
	assert.deepEqual(region.options.map((o) => o.value), traffic.regionGranularity.enum)
	for (const field of card.content.fields) {
		assert.ok(NL[field.label], `"${field.label}" is not in the Dutch catalogue`)
	}
})

test('the Pagina\'s tab shows the board\'s columns', () => {
	const pages = widget('portal-pages').content
	assert.deepEqual(pages.columns.map((c) => c.key), ['title', 'status', '@self.updated'])
	assert.equal(pages.columns[0].secondary, '{route}')
	assert.equal(NL[pages.columns[0].label], 'Titel en pad')
	assert.deepEqual(pages.columns[1].enum, ['published', 'draft'])
	assert.equal(NL[pages.columns[1].enumLabels.published], 'Gepubliceerd')
})

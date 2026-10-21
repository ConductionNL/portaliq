#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// integrations-page-board.spec.mjs: the Integrations page follows the
// portaliq/PtIntegraties board. It shows its title with a count line, "Add a
// connection" as the primary header button, the pills Alle, Werkt, Vraagt
// aandacht and Uitgeschakeld over portaliq's own connections, and a side panel
// with the connections that need attention and two notes on integriq. Every
// pill carries app=portaliq, so the page lists only portaliq's connections
// also when it is opened without the menu entry's query.
//
// Usage:
//   node --test tests/integrations-page-board.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const read = (name) => readFileSync(new URL(`../${name}`, import.meta.url), 'utf8')
const MANIFEST = JSON.parse(read('src/manifest.json'))
const NL = JSON.parse(read('l10n/nl.json')).translations
const CONFIG = MANIFEST.pages.find((page) => page.id === 'Integrations').config

test('the page names itself and counts the connections', () => {
	assert.equal(CONFIG.showTitle, true)
	assert.equal(NL[CONFIG.countText], '{total} koppelingen die Portaliq aanmeldt, getoond door integriq')
	const [button] = CONFIG.headerButtons
	assert.equal(button.action, 'add-integration')
	assert.equal(button.variant, 'primary')
	assert.ok(CONFIG.headerActions.some((action) => action.id === button.action))
	assert.equal(NL[button.label], 'Koppeling toevoegen')
})

test('the pills are the board\'s, over portaliq\'s own connections', () => {
	assert.deepEqual(
		CONFIG.quickFilters.map((pill) => NL[pill.label]),
		['Alle', 'Werkt', 'Vraagt aandacht', 'Uitgeschakeld'],
	)
	for (const pill of CONFIG.quickFilters) {
		assert.equal(pill.filter.app, 'portaliq', `"${pill.label}" lists other apps' connections`)
	}
	assert.deepEqual(
		CONFIG.quickFilters.map((pill) => pill.filter.status ?? null),
		[null, 'configured', 'error', 'disabled'],
	)
	assert.equal(CONFIG.quickFilters[0].default, true)
})

test('the side panel holds what needs attention and the notes on integriq', () => {
	assert.deepEqual(CONFIG.sidePanel.map((card) => card.id), ['integrations-attention', 'integrations-checked-by', 'integrations-add'])
	const attention = CONFIG.sidePanel[0]
	assert.equal(attention.type, 'record-list')
	assert.deepEqual(attention.content.filter, { app: 'portaliq', status: 'error' })
	for (const card of CONFIG.sidePanel) {
		assert.ok(NL[card.title], `the title of ${card.id} is not in the Dutch catalogue`)
	}
	for (const card of CONFIG.sidePanel.slice(1)) {
		assert.equal(card.type, 'banner')
		assert.ok(NL[card.content.text], `the text of ${card.id} is not in the Dutch catalogue`)
	}
})

#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// themes-portal-table.spec.mjs: the Themes page carries the table the
// portaliq/PtThemas board draws under its tiles, "Portalen en hun thema's":
// every portal with the theme it wears, a row opening the portal. It is an
// object table over the themes overview endpoint, placed full width below
// the tiles.
//
// Usage:
//   node --test tests/themes-portal-table.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const read = (name) => readFileSync(new URL(`../${name}`, import.meta.url), 'utf8')
const MANIFEST = JSON.parse(read('src/manifest.json'))
const NL = JSON.parse(read('l10n/nl.json')).translations
const THEMES = MANIFEST.pages.find((page) => page.id === 'Themes').config
const PAGE_IDS = new Set(MANIFEST.pages.map((page) => page.id))
const table = THEMES.widgets.find((w) => w.id === 'themes-portal-table')

test('the table lists every portal with its theme', () => {
	assert.ok(table, 'no themes-portal-table widget')
	// The rows come from the themes overview (themes-contrast-column), which
	// adds the domain and the contrast verdict to each portal.
	assert.equal(table.type, 'object-table')
	assert.equal(table.content.endpointSource.url, '/apps/portaliq/api/themes/portals')
	assert.deepEqual(table.content.columns.map((c) => c.key), ['title', 'themeName', 'contrast', 'houseStyle'])
	for (const column of table.content.columns.filter((c) => c.label)) {
		assert.ok(NL[column.label], `"${column.label}" is not in the Dutch catalogue`)
	}
	assert.ok(PAGE_IDS.has(table.content.rowRoute), 'a row opens no page')
	assert.ok(NL[table.title] && NL[table.content.emptyText])
})

test('it sits full width below the tiles', () => {
	const cell = THEMES.layout.find((entry) => entry.widgetId === 'themes-portal-table')
	assert.ok(cell, 'the table is declared but not placed')
	assert.equal(cell.gridX, 0)
	assert.equal(cell.gridWidth, 12)
	const tilesBottom = Math.max(...THEMES.layout.filter((e) => e !== cell && e.gridY <= cell.gridY).map((e) => e.gridY + e.gridHeight))
	assert.ok(cell.gridY >= tilesBottom, 'the table overlaps the tiles')
})

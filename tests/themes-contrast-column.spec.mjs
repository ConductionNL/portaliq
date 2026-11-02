#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// themes-contrast-column.spec.mjs: the Themes page lists the portals from
// /api/themes/portals with the domain under the title, the theme, the contrast
// as a pill and a link to change the house style, with the contrast note
// under the table while a theme is hard to read (PtThemas).
//
// Usage:
//   node --test tests/themes-contrast-column.spec.mjs
//
// @spec openspec/specs/portal-themes/spec.md#requirement-the-themes-page-shows-the-contrast-a-link-and-the-note

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const read = (name) => readFileSync(new URL(`../${name}`, import.meta.url), 'utf8')
const MANIFEST = JSON.parse(read('src/manifest.json'))
const ROUTES = read('appinfo/routes.php')
const THEMES = MANIFEST.pages.find((page) => page.id === 'Themes').config
const NL = MANIFEST.i18n.labels.nl
const ENDPOINT = '/apps/portaliq/api/themes/portals'

test('the overview route answers at the admin controller', () => {
	assert.match(ROUTES, /'themeOverview#portals', 'url' => '\/api\/themes\/portals', 'verb' => 'GET'/)
})

test('the table reads the overview with the board columns', () => {
	const table = THEMES.widgets.find((widget) => widget.id === 'themes-portal-table')
	assert.equal(table.type, 'object-table')
	assert.deepEqual(table.content.endpointSource, { url: ENDPOINT, responsePath: 'portals' })
	const columns = Object.fromEntries(table.content.columns.map((column) => [column.key, column]))
	assert.equal(columns.title.secondary, 'domain')
	assert.ok(columns.themeName)
	assert.equal(columns.contrast.widget, 'badge')
	assert.deepEqual(columns.contrast.widgetProps.colorMap, { readable: 'success', poor: 'warning', unchecked: 'default' })
	assert.equal(NL[columns.contrast.enumLabels.poor], 'Slecht leesbaar')
	assert.equal(columns.houseStyle.widget, 'link')
	assert.equal(columns.houseStyle.widgetProps.route, 'PortalDetail')
	assert.equal(NL[columns.houseStyle.enumLabels.change], 'Huisstijl wijzigen')
})

test('the note shows only while a theme is hard to read, under the table', () => {
	const note = THEMES.widgets.find((widget) => widget.id === 'themes-contrast-note')
	assert.equal(note.type, 'banner')
	assert.deepEqual(note.content.visibleWhen, { endpoint: ENDPOINT, field: 'poorCount', op: 'gt', value: 0 })
	const table = THEMES.layout.find((cell) => cell.widgetId === 'themes-portal-table')
	const cell = THEMES.layout.find((entry) => entry.widgetId === 'themes-contrast-note')
	assert.equal(cell.gridY, table.gridY + table.gridHeight)
	assert.ok(NL[note.content.text].includes('4,5 op 1'))
})

#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-edit-mode.spec.mjs: editing a page on the portal itself
// (portal-in-place-editing A2, REQ-PIE-006 to REQ-PIE-008).
//
// Usage:
//   node --test tests/site-edit-mode.spec.mjs

import assert from 'node:assert/strict'
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { cellOf, cellStyleOf, normaliseWidgets, storedWidget } from '../src/editor/index.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const read = (path) => readFileSync(join(ROOT, path), 'utf8')

const WIDGETS = [
	{ id: 'a', widgetKey: 'markdown', gridX: 0, gridY: 0, gridWidth: 6, gridHeight: 4 },
	{ id: 'b', widgetKey: 'markdown', gridX: 6, gridY: 0, gridWidth: 6, gridHeight: 4 },
	{ id: 'c', widgetKey: 'markdown', gridX: 10, gridY: 4, gridWidth: 6, gridHeight: 2 },
	{ id: 'd', widgetKey: 'markdown', gridX: -3, gridY: -1, gridWidth: 0, gridHeight: 0 },
]

test('renderer and editor agree on geometry', () => {
	// The editor stores what it shows; the renderer styles what is stored.
	// Both go through cellOf, so for every widget the stored cell and the
	// rendered style name the same columns and rows.
	for (const widget of WIDGETS) {
		const stored = storedWidget(normaliseWidgets([widget])[0])
		const style = cellStyleOf(widget)
		assert.equal(style.gridColumn, `${stored.gridX + 1} / span ${stored.gridWidth}`, widget.id)
		assert.equal(style.gridRow, `${stored.gridY + 1} / span ${stored.gridHeight}`, widget.id)
	}
	assert.deepEqual(cellOf(WIDGETS[0]), { gridX: 0, gridY: 0, gridWidth: 6, gridHeight: 4 })
	assert.deepEqual(cellOf(WIDGETS[1]), { gridX: 6, gridY: 0, gridWidth: 6, gridHeight: 4 })
	// Clamped inside 12 columns, not run past the edge.
	assert.deepEqual(cellOf(WIDGETS[2]), { gridX: 10, gridY: 4, gridWidth: 2, gridHeight: 2 })
	assert.deepEqual(cellOf(WIDGETS[3]), { gridX: 0, gridY: 0, gridWidth: 12, gridHeight: 1 })
})

test('the public renderer places cells with the shared function', () => {
	const grid = read('src/site/components/WidgetGrid.vue')
	assert.match(grid, /from '\.\.\/\.\.\/editor\/geometry\.js'/)
	assert.match(grid, /cellStyleOf\(widget\)/)
})

test('the site loads the editor lazily, never in its entry', () => {
	const app = read('src/site/App.vue')
	assert.match(app, /import\(\s*\/\* webpackChunkName: "site-editor" \*\/\s*'\.\.\/editor\/SiteEditMode\.vue'\s*\)/)
	assert.doesNotMatch(app, /^import .*editor\/SiteEditMode/m)
	assert.doesNotMatch(app, /^import .*editor\/index/m)
})

test('the edit control offers editing in place for a page, and the designer as a second way', () => {
	const button = read('src/site/components/SiteEditButton.vue')
	assert.match(button, /Deze pagina bewerken/)
	assert.match(button, /emit: 'edit'/)
	assert.match(button, /\$emit\(action\.emit/)
	assert.match(button, /In de beheeromgeving openen/)
})

test('the portal edit mode is the shared editor with a public palette', () => {
	const mode = read('src/editor/SiteEditMode.vue')
	assert.match(mode, /createPageEditor\(/)
	assert.match(mode, /<PageGridEditor/)
	assert.match(mode, /<WidgetPaletteDialog[\s\S]*?publicOnly/)
	assert.match(mode, /<PageHistoryDialog/)
	for (const id of ['site-edit-save', 'site-edit-publish', 'site-edit-discard', 'site-edit-undo', 'site-edit-redo', 'site-edit-leave', 'site-edit-history']) {
		assert.match(mode, new RegExp(`data-testid="${id}"`), id)
	}
})

test('the palette limited to public widgets offers only what the renderer mounts', async () => {
	const palette = read('src/dialogs/WidgetPaletteDialog.vue')
	assert.match(palette, /publicOnly/)
	assert.match(palette, /entry\.publicSafe/)
})

test('the site entry stays under its budget and the editor is its own chunk', { skip: !existsSync(join(ROOT, 'js', 'portaliq-site.js')) && 'no site build in js/' }, () => {
	const entry = statSync(join(ROOT, 'js', 'portaliq-site.js')).size
	assert.ok(entry < 410 * 1024, `portaliq-site.js is ${entry} bytes`)
	const chunks = readdirSync(join(ROOT, 'js')).filter((f) => /site-editor/.test(f) && f.endsWith('.js'))
	assert.ok(chunks.length > 0, 'no site-editor chunk in js/')
})

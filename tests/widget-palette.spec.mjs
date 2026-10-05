#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// widget-palette.spec.mjs: the palette's groups and its search
// (site-nlds-widget-palette REQ-SNW-001), and placing a widget by drop, by
// click and by key (REQ-SNW-002).
//
// Grouping, searching and the cell a drop means are plain functions, so this
// runs them without a browser. The gesture itself is one line of Vue over
// these answers; what could go wrong silently is the arithmetic and the
// matching, and that is what is asserted here.
//
// Usage:
//   node --test tests/widget-palette.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { cellFromDrop, GRID_COLUMNS } from '../src/editor/geometry.js'
import { addWidget, addWidgetAt } from '../src/editor/gridModel.js'
import {
	DUTCH_LABELS,
	matchesQuery,
	NOT_PUBLIC_GROUP,
	PALETTE_GROUPS,
	paletteGroups,
	paletteHitCount,
	paletteLabel,
} from '../src/lib/widgetPalette.js'

/** A catalogue to group and search, in the shape `widgetCatalogue()` answers. */
const CATALOGUE = [
	{
		key: 'nlLink',
		label: 'Link',
		publicSafe: true,
		reason: '',
		group: 'content',
		nlds: 'Link',
		synonyms: ['hyperlink', 'verwijzing', 'koppeling'],
	},
	{
		key: 'nlCases',
		label: 'Zaakkaarten',
		publicSafe: true,
		reason: '',
		group: 'mijn',
		nlds: 'Case Card',
		synonyms: ['zaak', 'mijn zaken', 'dossier'],
	},
	{
		key: 'siteNavigation',
		label: 'Site navigation',
		publicSafe: true,
		reason: '',
		group: '',
		nlds: '',
		synonyms: [],
	},
	{
		key: 'form',
		label: 'form',
		publicSafe: true,
		reason: '',
		group: '',
		nlds: '',
		synonyms: [],
	},
	{
		key: 'table',
		label: 'Tabel',
		publicSafe: false,
		reason: 'Deze widget wordt niet getoond op een openbare pagina.',
		group: '',
		nlds: '',
		synonyms: [],
	},
]

test('the palette shows the six groups in order, and what is not public last', () => {
	assert.deepEqual(
		PALETTE_GROUPS.map((entry) => entry.label),
		['Inhoud', 'Navigatie', 'Formulieren', 'Terugkoppeling', 'Mijn omgeving', 'Opmaak'],
	)

	const groups = paletteGroups(CATALOGUE)
	assert.deepEqual(
		groups.map((group) => group.group),
		['content', 'mijn', 'other', NOT_PUBLIC_GROUP.group],
		'a group with nothing in it is left out, and the not-public entries come last',
	)
	assert.deepEqual(
		groups.at(-1).entries.map((entry) => entry.key),
		['table'],
	)
})

test('an author types the word for the thing, not the design system name', () => {
	// The search reads the Dutch label, the synonyms, the NL Design System
	// name and the key. "zaak" is the case cards' synonym and appears in no
	// label; a search that read labels alone would find nothing.
	const zaak = paletteGroups(CATALOGUE, 'zaak')
	assert.deepEqual(
		zaak.flatMap((group) => group.entries.map((entry) => entry.key)),
		['nlCases'],
	)
	assert.equal(paletteHitCount(CATALOGUE, 'zaak'), 1, 'the number the palette announces')

	// Each of the four haystacks on its own.
	assert.ok(matchesQuery(CATALOGUE[0], 'Link'), 'the label')
	assert.ok(matchesQuery(CATALOGUE[0], 'koppeling'), 'a synonym')
	assert.ok(matchesQuery(CATALOGUE[1], 'case card'), 'the NL Design System name')
	assert.ok(matchesQuery(CATALOGUE[1], 'nlcases'), 'the key')

	// Case and part words both match, and a word nothing answers to finds
	// nothing rather than everything.
	assert.ok(matchesQuery(CATALOGUE[1], 'ZAAK'))
	assert.ok(matchesQuery(CATALOGUE[1], 'doss'))
	assert.equal(paletteHitCount(CATALOGUE, 'parkeervergunning'), 0)
	assert.deepEqual(paletteGroups(CATALOGUE, 'parkeervergunning'), [])

	// An empty search is not a filter.
	assert.equal(paletteHitCount(CATALOGUE, ''), CATALOGUE.length)
	assert.equal(paletteHitCount(CATALOGUE, '   '), CATALOGUE.length)
})

test('the two widgets whose own name is English read Dutch in the palette', () => {
	// T2 names these two: an author read "Site navigation" and "form", which
	// is the key dressed up rather than a label.
	assert.equal(paletteLabel(CATALOGUE[2]), 'Menu van deze site')
	assert.equal(paletteLabel(CATALOGUE[3]), 'Formulier')
	assert.deepEqual(Object.keys(DUTCH_LABELS), ['siteNavigation', 'form'])

	// And they are findable by that Dutch label, which is the point of giving
	// them one.
	assert.equal(paletteHitCount(CATALOGUE, 'menu'), 1)
	assert.equal(paletteHitCount(CATALOGUE, 'formulier'), 1)

	// Every widget an author may place reads something other than its key.
	for (const group of paletteGroups(CATALOGUE)) {
		for (const entry of group.entries) {
			assert.notEqual(entry.label, entry.key, `${entry.key} reads as its own key`)
		}
	}
})

test('a drop lands in the cell it was aimed at, clamped into the grid', () => {
	// A canvas 1200 wide starting at x=10: half way across is column 6.
	assert.deepEqual(
		cellFromDrop({ x: 610, y: 120, left: 10, top: 0, width: 1200 }),
		{ gridX: 6, gridY: 2 },
	)

	// The right edge is the last column, not column 12, which does not exist.
	assert.deepEqual(
		cellFromDrop({ x: 1210, y: 0, left: 10, top: 0, width: 1200 }),
		{ gridX: GRID_COLUMNS - 1, gridY: 0 },
	)

	// Above and left of the canvas is the first cell, not a negative one.
	assert.deepEqual(cellFromDrop({ x: -50, y: -50, left: 0, top: 0, width: 1200 }), {
		gridX: 0,
		gridY: 0,
	})

	// An unmeasurable canvas (an empty page before layout) is the first cell.
	assert.deepEqual(cellFromDrop({ x: 100, y: 100, left: 0, top: 0, width: 0 }), {
		gridX: 0,
		gridY: 0,
	})
})

test('a dropped widget keeps its whole width on the grid', () => {
	const dropped = addWidgetAt([], 'nlLink', { gridWidth: 4, gridHeight: 2 }, { gridX: 11, gridY: 3 })
	const placed = dropped.widgets[0]

	assert.equal(placed.gridX + placed.gridWidth, GRID_COLUMNS, 'pushed in, not cut off')
	assert.equal(placed.gridY, 3)
	assert.equal(placed.widgetKey, 'nlLink')
	assert.equal(placed.id, 'nlLink-1', 'its own identifier')
	assert.deepEqual(placed.props, {})

	// A drop with no usable cell still places something: the gesture must not
	// be able to do nothing at all.
	const nowhere = addWidgetAt([], 'nlLink', { gridWidth: 4, gridHeight: 2 }, null)
	assert.equal(nowhere.widgets.length, 1)
})

test('placing without a pointer puts the widget where an author will find it', () => {
	// REQ-SNW-002: a click or Enter on a palette entry places the widget. The
	// dialog's entry IS a button, so the browser fires the same click for both
	// and this is the placement they share. It appends below everything, which
	// `addWidget` documents as deliberate: a widget squeezed into a gap in the
	// middle looks like nothing happened.
	const first = addWidget([], 'nlLink', { gridWidth: 3, gridHeight: 1 })
	assert.deepEqual(
		{ x: first.widgets[0].gridX, y: first.widgets[0].gridY },
		{ x: 0, y: 0 },
		'on an empty page the first cell is also the one below everything',
	)

	const second = addWidget(first.widgets, 'nlLink', { gridWidth: 3, gridHeight: 1 })
	assert.equal(second.widgets[1].gridY, 1, 'the next one is below the first')
	assert.equal(second.widgets[1].id, 'nlLink-2', 'and has its own identifier')

	// Two ways in, one result: a drop on the cell a keyboard placement would
	// choose produces the same geometry, so neither gesture is a different
	// feature.
	const dropped = addWidgetAt(first.widgets, 'nlLink', { gridWidth: 3, gridHeight: 1 }, { gridX: 0, gridY: 1 })
	assert.deepEqual(
		{ x: dropped.widgets[1].gridX, y: dropped.widgets[1].gridY, w: dropped.widgets[1].gridWidth },
		{
			x: second.widgets[1].gridX,
			y: second.widgets[1].gridY,
			w: second.widgets[1].gridWidth,
		},
	)
})

// ---------------------------------------------------------------------------
// THE PALETTE MUST NOT COVER ITS OWN DROP TARGET (REQ-SNW-002).
//
// Found live on 4 Oct 2026 and not by any test: the palette was an `aria-modal`
// NcDialog with a full-screen backdrop, so while it was open the canvas behind
// it took no pointer at ANY coordinate. Playwright's drop over
// `designer-canvas` was intercepted by the dialog's own header after 164
// retries. Every unit test above still passed, because grouping, matching and
// `cellFromDrop` were all correct: the gesture was wired and walled off.
//
// So these read the wiring the arithmetic cannot see. They are source
// assertions rather than mounts because this repo has no browser in its unit
// run, and a mount without layout cannot tell a backdrop from no backdrop
// either. Each one names the fault it would catch.
// ---------------------------------------------------------------------------

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const source = (path) => readFileSync(join(ROOT, path), 'utf8')

/**
 * A file's code with its comments taken out.
 *
 * ⚠️ THE COMMENTS ARE WHY THIS EXISTS. The checks below forbid `NcDialog` and
 * `aria-modal`, and the panel's own header explains at length that it used to be
 * both. Matching the raw file failed on that explanation, which is a test
 * failing on its own documentation: the first run of this test caught the
 * sentence, not the code.
 *
 * @param {string} path The file, relative to the repository root.
 * @return {string} The file without HTML, block or line comments.
 */
function code(path) {
	return source(path)
		.replace(/<!--[\s\S]*?-->/g, '')
		.replace(/\/\*[\s\S]*?\*\//g, '')
		.replace(/^\s*\/\/.*$/gm, '')
}

test('the palette is a non-modal panel, so nothing of it is over the canvas', () => {
	const panel = code('src/editor/WidgetPalettePanel.vue')

	// The backdrop came from NcDialog. A dialog is how this regresses.
	assert.doesNotMatch(panel, /NcDialog|NcModal/, 'the palette is a dialog again')
	assert.doesNotMatch(panel, /aria-modal/, 'a modal palette walls off the grid')

	// What it is instead: a labelled region, in the DOM only while it is open.
	assert.match(panel, /<aside[\s\S]*?v-if="open"/)
	assert.match(panel, /role="region"/)
	assert.match(panel, /id="widget-palette"/)
	assert.match(panel, /data-testid="widget-palette"/)
})

test('the panel sits in the editor pane row, beside the canvas', () => {
	const editor = code('src/editor/PageGridEditor.vue')

	// It is INSIDE the pane row and BEFORE the canvas. A palette rendered after
	// the editor is under the grid, not beside it.
	const panes = editor.indexOf('page-grid-editor__panes')
	const panel = editor.indexOf('<WidgetPalettePanel')
	const canvas = editor.indexOf('data-testid="designer-canvas"')
	assert.ok(panes > -1 && panel > panes, 'the palette is in the editor pane row')
	assert.ok(panel < canvas, 'the palette column comes before the canvas')

	// NO HOST MOUNTS IT. Both hosts used to, and both put it over the page; a
	// host can only say whether it is open. That is what keeps the panel's
	// position a property of the editor rather than of whoever embeds it.
	for (const host of [
		'src/views/PageLayoutDesigner.vue',
		'src/editor/SiteEditMode.vue',
	]) {
		const text = code(host)
		assert.doesNotMatch(text, /WidgetPalette/, `${host} mounts the palette itself`)
		assert.match(text, /v-model:paletteOpen="paletteOpen"/, host)
	}

	// And the portal edit mode still asks for the public widgets only.
	assert.match(code('src/editor/SiteEditMode.vue'), /<PageGridEditor[\s\S]*?publicOnly/)
})

test('an empty canvas is big enough to drop on', () => {
	// A page with no widgets renders one line of hint text. Without a minimum
	// height there is nothing under the pointer to drop onto, and the drop lands
	// on whatever follows the editor.
	const editor = source('src/editor/PageGridEditor.vue')
	const block = editor.slice(editor.indexOf('.page-grid-editor__canvas {'))
	const height = block.match(/min-height:\s*(\d+)px/)
	assert.ok(height, 'the canvas declares a minimum height')
	assert.ok(Number(height[1]) >= 120, `the canvas is ${height[1]}px tall when empty`)
})

test('the drag type is one module both sides read', () => {
	// The canvas used to import PALETTE_DRAG_TYPE from the palette's .vue file,
	// so the editor depended on the palette for a string and a rename of the
	// palette broke the drop silently: `getData()` for a type nobody set answers
	// an empty string, and the handler returns without placing anything.
	const drag = source('src/editor/paletteDrag.js')
	assert.match(drag, /export const PALETTE_DRAG_TYPE = '[^']+'/)

	for (const file of [
		'src/editor/PageGridEditor.vue',
		'src/editor/WidgetPalettePanel.vue',
	]) {
		const text = code(file)
		assert.match(text, /from '\.\/paletteDrag\.js'/, file)
		assert.doesNotMatch(
			text,
			/PALETTE_DRAG_TYPE\s*=/,
			`${file} declares its own copy of the type`,
		)
	}
})

test('a panel that traps nothing still answers the keyboard', () => {
	const panel = code('src/editor/WidgetPalettePanel.vue')

	// Escape closes it, and closing gives focus back. Without the second half an
	// author who presses Escape is left with focus on nothing at all.
	assert.match(panel, /@keydown\.esc[\s\S]{0,20}="close"/)
	assert.match(panel, /this\.\$refs\.search\?\.focus\?\.\(\)/)
	assert.match(panel, /opener\?\.isConnected/)

	// The tile is still a button, so a click and Enter are the same act, and it
	// still carries the drag.
	assert.match(panel, /<button[\s\S]*?draggable="true"[\s\S]*?@dragstart="startDrag/)
	assert.match(panel, /@click="choose\(entry\)"/)

	// And the control that opens it says so, which is what a region needs
	// instead of the announcement a dialog gets for free.
	for (const host of [
		'src/views/PageLayoutDesigner.vue',
		'src/editor/SiteEditMode.vue',
	]) {
		const text = code(host)
		assert.match(text, /:aria-expanded="paletteOpen"/, host)
		assert.match(text, /aria-controls="widget-palette"/, host)
	}
})

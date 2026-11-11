#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// grid-model-golden.spec.mjs: where a new widget is placed (below everything,
// or at a dropped cell, clamped onto the grid) and which geometry a layout
// engine's answer changes, pinned in a golden file before each was split into
// named steps.
//
// Usage:
//   node --test tests/grid-model-golden.spec.mjs
//   GOLDEN_RECORD=1 node --test tests/grid-model-golden.spec.mjs

import { test } from 'node:test'
import { addWidget, addWidgetAt, applyLayout } from '../src/editor/gridModel.js'
import { golden } from './support/golden.mjs'

const PAGE = [
	{ id: 'nlLink-1', widgetKey: 'nlLink', gridX: 0, gridY: 0, gridWidth: 6, gridHeight: 2, props: { a: 1 } },
	{ id: 'hero', widgetKey: 'hero', gridX: 6, gridY: '3', gridWidth: 6, gridHeight: 'x', props: {} },
]

test('placing widgets and taking a layout', () => {
	const file = golden('grid-model')
	file.check('addWidget', [
		addWidget(null, 'nlLink', null),
		addWidget(PAGE, 'nlLink', { gridWidth: 20, gridHeight: 0 }),
		addWidget(PAGE, 'hero', { gridWidth: '3', gridHeight: '5' }),
	])
	file.check('addWidgetAt', [
		addWidgetAt(PAGE, 'nlLink', { gridWidth: 4, gridHeight: 2 }, { gridX: 11.4, gridY: -2 }),
		addWidgetAt(PAGE, 'nlLink', { gridWidth: 0, gridHeight: -3 }, { gridX: '2', gridY: '7.6' }),
		addWidgetAt(PAGE, 'nlLink', { gridWidth: 40 }, { gridX: -5, gridY: 0 }),
		addWidgetAt(PAGE, 'nlLink', null, { gridX: 'x', gridY: 1 }),
		addWidgetAt(PAGE, 'nlLink', null, null),
		addWidgetAt([], 'nlLink', { gridWidth: 3, gridHeight: 1 }, { gridX: Infinity, gridY: 0 }),
	])
	file.check('applyLayout', [
		applyLayout(PAGE, null),
		applyLayout(PAGE, [{ id: 'nlLink-1', gridX: 0, gridY: 0, gridWidth: 6, gridHeight: 2 }]),
		applyLayout(PAGE, [
			{ id: 'gone', gridX: 1 },
			{ id: 'hero', gridX: '2', gridY: 'x', gridWidth: 0, gridHeight: 4, props: { injected: true } },
			{ id: 'nlLink-1', gridX: 1, gridY: 1, gridWidth: '4', gridHeight: null },
		]),
	])
	file.save()
})

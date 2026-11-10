#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// grid-help-helpers-golden.spec.mjs: where a dropped widget lands and how a
// placement is clamped, the help a form shows and the section an entry is
// help for, a kept date choice and the measured document height, pinned in
// a golden file before each was split into named steps.
//
// Usage:
//   node --test tests/grid-help-helpers-golden.spec.mjs
//   GOLDEN_RECORD=1 node --test tests/grid-help-helpers-golden.spec.mjs

import { test } from 'node:test'
import { cellFromDrop, cellOf } from '../src/editor/geometry.js'
import { measureDocument } from '../src/shared/embedHeight.js'
import { mergeHelp, sectionOf } from '../src/site/lib/help.js'
import { takeChoice } from '../src/site/widgets/nlPublicDetail/detail.js'
import { golden } from './support/golden.mjs'

/**
 * A storage holding one value, that can refuse.
 *
 * @param {object} items The stored items.
 * @param {boolean} refuse Whether every call throws.
 * @return {object} The storage and what it removed.
 */
function storage(items, refuse = false) {
	const removed = []
	return {
		removed,
		getItem: (key) => {
			if (refuse) {
				throw new Error('refused')
			}
			return Object.hasOwn(items, key) ? items[key] : null
		},
		removeItem: (key) => removed.push(key),
	}
}

test('the grid, help, choice and height helpers answer as pinned', () => {
	const file = golden('grid-help-helpers')
	file.check('cellFromDrop', [
		[null],
		[{ width: 0 }],
		[{ width: 'x' }],
		[{ width: 1200, x: 610, y: 250, left: 10, top: 50 }],
		[{ width: 1200, x: 5000, y: 10 }],
		[{ width: 1200, x: -50, y: -50 }],
		[{ width: 1200, x: 'a', y: 'b' }],
		[{ width: 1200, x: 600, y: 300 }, 0],
		[{ width: 1200, x: 600, y: 300 }, 'x'],
		[{ width: 1200, x: 600, y: 300 }, 150],
		[{ width: 1200, x: 600, y: 300 }, 0.5],
	].map((args) => cellFromDrop(...args)))
	file.check('cellOf', [
		null,
		{},
		{ gridX: 3.7, gridY: 2.2, gridWidth: 4.9, gridHeight: 2.5 },
		{ gridX: 20, gridWidth: 5 },
		{ gridX: 8, gridWidth: 10 },
		{ gridX: -3, gridY: -4, gridWidth: -1, gridHeight: 0 },
		{ gridX: 'x', gridWidth: '6' },
	].map((widget) => cellOf(widget)))
	file.check('mergeHelp', [
		[null, null],
		[{ intro: ' Bel ons ', phone: '14 0', email: 5, other: 'x' }, null],
		[{ intro: 'Portaal', hours: '9-5' }, { intro: ' Formulier ', hours: '   ', desk: 'Balie' }],
		['x', { phoneNote: 'n' }],
	].map(([portal, form]) => mergeHelp(portal, form)))
	file.check('sectionOf', [
		[null, true],
		[{ special: 'tasks' }, false],
		[{ special: 'inbox' }],
		[{ special: 'messages' }],
		[{ special: 'cases' }],
		[{ special: 'myCases' }],
		[{ special: 'contacts' }],
		[{ special: 'other' }],
		[{ special: 'toString' }],
		[null],
	].map((args) => sectionOf(...args)))
	const dates = [{ id: 'd1', places: 3 }, { id: 'd2', places: 0 }, { id: 'd3' }]
	const choices = []
	for (const [items, refuse, max] of [
		[{}, false, undefined],
		[{ 'portaliq.detailChoice:/c': JSON.stringify({ date: 'd1', count: 4 }) }, false, undefined],
		[{ 'portaliq.detailChoice:/c': JSON.stringify({ date: 'd2', count: 40 }) }, false, 10],
		[{ 'portaliq.detailChoice:/c': JSON.stringify({ date: 'd9' }) }, false, undefined],
		[{ 'portaliq.detailChoice:/c': '{broken' }, false, undefined],
		[{ 'portaliq.detailChoice:/c': 'null' }, false, undefined],
		[{}, true, undefined],
	]) {
		const store = storage(items, refuse)
		choices.push([takeChoice(store, '/c', dates, max), store.removed])
	}
	choices.push([takeChoice(null, '/c', dates), []])
	choices.push([takeChoice(storage({ 'portaliq.detailChoice:/c': JSON.stringify({ date: 'd3', count: 2 }) }), '/c', 'x'), []])
	file.check('takeChoice', choices)
	file.check('measureDocument', [
		null,
		{ body: {} },
		{ documentElement: {} },
		{ body: {}, documentElement: {} },
		{ body: { scrollHeight: 900, offsetHeight: 1200 }, documentElement: { clientHeight: 800, scrollHeight: 1000, offsetHeight: 1100 } },
		{ body: { scrollHeight: 0 }, documentElement: { offsetHeight: 2000 } },
	].map((doc) => measureDocument(doc)))
	file.save()
})

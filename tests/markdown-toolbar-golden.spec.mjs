#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// markdown-toolbar-golden.spec.mjs: what each text toolbar transformation,
// shortcut and focus move answers over a grid of texts and selections, pinned
// in a golden file before they were split into named steps.
//
// Usage:
//   node --test tests/markdown-toolbar-golden.spec.mjs
//   GOLDEN_RECORD=1 node --test tests/markdown-toolbar-golden.spec.mjs

import { test } from 'node:test'
import {
	applyBold,
	applyHeading,
	applyItalic,
	applyList,
	shortcutFor,
	toolbarIndexFor,
} from '../src/editor/markdownToolbar.js'
import { golden } from './support/golden.mjs'

const TEXTS = [
	'',
	'word',
	' word ',
	'**bold**',
	'_it_',
	'one\ntwo\n\nthree',
	'- a\n- b',
	'- a\nb\n',
	'# Title\nbody',
	'### Deep\n## Two',
]

/**
 * Every selection of a text at a few positions, stale ones included.
 *
 * @param {string} value The text.
 * @return {Array<object>} The selections.
 */
function selections(value) {
	const points = [...new Set([0, 1, Math.floor(value.length / 2), value.length, value.length + 3])]
	const out = [{ value, start: 'x', end: null }, { value: null, start: 0, end: 0 }]
	for (const start of points) {
		for (const end of points) {
			out.push({ value, start, end })
		}
	}
	return out
}

test('every transformation over a grid of selections', () => {
	const file = golden('markdown-toolbar')
	for (const [name, transform] of Object.entries({ applyBold, applyItalic, applyHeading, applyList })) {
		file.check(name, TEXTS.flatMap(selections).map((selection) => transform(selection)))
	}
	const keys = ['b', 'B', 'i', 'x', undefined]
	const mods = [{}, { ctrlKey: true }, { metaKey: true }, { ctrlKey: true, shiftKey: true }, { metaKey: true, altKey: true }]
	const names = new Map([[applyBold, 'bold'], [applyItalic, 'italic']])
	file.check('shortcutFor', [null, ...keys.flatMap((key) => mods.map((mod) => ({ key, ...mod })))].map((event) => names.get(shortcutFor(event)) ?? null))
	const moves = []
	for (const count of [0, 1, 4, 2.5, '4']) {
		for (const current of [-1, 0, 2, 3, 9, 'x']) {
			for (const key of ['ArrowRight', 'ArrowLeft', 'Home', 'End', 'Tab']) {
				moves.push(toolbarIndexFor(current, key, count))
			}
		}
	}
	file.check('toolbarIndexFor', moves)
	file.save()
})

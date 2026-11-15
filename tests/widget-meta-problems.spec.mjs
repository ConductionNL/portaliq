#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// widget-meta-problems.spec.mjs: every problem metaProblems reports for a
// widget meta, in its order and wording, pinned before the checks were split
// into a list of named checks.
//
// Usage:
//   node --test tests/widget-meta-problems.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { metaProblems } from '../src/site/widgets/index.js'

const SOUND = {
	key: 'nlAlert',
	group: 'feedback',
	label: 'Melding',
	nlds: 'Alert',
	synonyms: [],
	fields: [],
	defaultSize: { gridWidth: 12, gridHeight: 2 },
	scope: 'public',
}

test('a sound meta has no problem, also when signed in only', () => {
	assert.deepEqual(metaProblems(SOUND, 'nlAlert'), [])
	assert.deepEqual(metaProblems({ ...SOUND, scope: 'signedIn' }, 'nlAlert'), [])
})

test('no meta at all is one problem', () => {
	for (const meta of [null, undefined, 'x', 3]) {
		assert.deepEqual(metaProblems(meta, 'nlX'), ['nlX: no meta'])
	}
})

test('every problem of a broken meta, in order', () => {
	assert.deepEqual(metaProblems({}, 'nlX'), [
		'nlX: meta.key is undefined',
		'nlX: group undefined is not one of the six',
		'nlX: label is empty',
		'nlX: nlds is empty',
		'nlX: synonyms is not a list',
		'nlX: fields is not a list',
		'nlX: defaultSize is not two whole numbers',
		'nlX: scope undefined is neither public nor signedIn',
	])
	assert.deepEqual(
		metaProblems({ ...SOUND, key: 'other', group: 'misc', label: '  ', nlds: 3, scope: 'all' }, 'nlAlert'),
		[
			'nlAlert: meta.key is "other"',
			'nlAlert: group "misc" is not one of the six',
			'nlAlert: label is empty',
			'nlAlert: nlds is empty',
			'nlAlert: scope "all" is neither public nor signedIn',
		],
	)
})

test('each size that is not two whole numbers', () => {
	for (const defaultSize of [null, { gridWidth: 1.5, gridHeight: 2 }, { gridWidth: 2, gridHeight: '2' }, {}]) {
		assert.deepEqual(metaProblems({ ...SOUND, defaultSize }, 'nlAlert'), ['nlAlert: defaultSize is not two whole numbers'])
	}
})

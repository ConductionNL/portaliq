#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// edit-history-intent.spec.mjs: which key presses the page editor reads as
// undo or redo, and where it leaves them to the browser, pinned branch by
// branch before historyIntent was split into named checks.
//
// Usage:
//   node --test tests/edit-history-intent.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createEditHistory, historyIntent } from '../src/editor/editHistory.js'

const press = (over = {}) => ({ ctrlKey: true, key: 'z', target: { tagName: 'DIV' }, ...over })

test('without the command key, with alt, or without an event there is no intent', () => {
	assert.equal(historyIntent(null), null)
	assert.equal(historyIntent(undefined), null)
	assert.equal(historyIntent(press({ ctrlKey: false })), null)
	assert.equal(historyIntent(press({ altKey: true })), null)
	assert.equal(historyIntent(press({ ctrlKey: false, metaKey: true, altKey: true })), null)
})

test('a text field keeps its own undo; a checkbox, radio or button does not', () => {
	for (const tagName of ['TEXTAREA', 'textarea', 'SELECT']) {
		assert.equal(historyIntent(press({ target: { tagName } })), null, tagName)
	}
	for (const type of [undefined, '', 'text', 'number', 'email']) {
		assert.equal(historyIntent(press({ target: { tagName: 'input', type } })), null, String(type))
	}
	for (const type of ['checkbox', 'radio', 'button', 'submit']) {
		assert.equal(historyIntent(press({ target: { tagName: 'INPUT', type } })), 'undo', type)
	}
	assert.equal(historyIntent(press({ target: { tagName: 'SPAN', isContentEditable: true } })), null)
	assert.equal(historyIntent(press({ target: undefined })), 'undo')
	assert.equal(historyIntent(press({ target: {} })), 'undo')
})

test('z undoes, shift-z and y redo, shift-y and other keys do nothing', () => {
	assert.equal(historyIntent(press({ key: 'Z' })), 'undo')
	assert.equal(historyIntent(press({ key: 'z', shiftKey: true })), 'redo')
	assert.equal(historyIntent(press({ key: 'Y' })), 'redo')
	assert.equal(historyIntent(press({ key: 'y', shiftKey: true })), null)
	assert.equal(historyIntent(press({ key: 's' })), null)
	assert.equal(historyIntent(press({ key: undefined })), null)
	assert.equal(historyIntent(press({ metaKey: true, ctrlKey: false, key: 'y' })), 'redo')
})

test('records with the same coalescing key make one step, and an undo ends the step', () => {
	const history = createEditHistory()
	history.record({ n: 1 }, 'title')
	history.record({ n: 2 }, 'title')
	history.record({ n: 3 }, null)
	history.record({ n: 4 }, null)
	assert.deepEqual(history.undo({ n: 5 }), { n: 4 })
	history.record({ n: 6 }, 'body')
	history.record({ n: 7 }, 'body')
	assert.deepEqual(history.undo({ n: 8 }), { n: 6 })
	assert.deepEqual(history.undo({ n: 9 }), { n: 3 })
	assert.deepEqual(history.undo({ n: 10 }), { n: 1 })
	assert.equal(history.undo({ n: 11 }), null)
	const fresh = createEditHistory()
	fresh.record({ n: 1 }, 'a')
	fresh.undo({ n: 2 })
	fresh.record({ n: 3 }, 'a')
	assert.deepEqual(fresh.undo({ n: 4 }), { n: 3 }, 'a step after an undo is new even with the same key')
})

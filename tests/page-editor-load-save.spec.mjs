#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// page-editor-load-save.spec.mjs: what the page editor's state holds after a
// load (the body, the draft, the selection kept or dropped) and the message
// a failed load or save shows for each refusal, pinned before the editor's
// load and write were split into named steps.
//
// Usage:
//   node --test tests/page-editor-load-save.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createPageEditor, createPageSaver } from '../src/editor/index.js'

const t = (_app, text) => text
const PAGE = {
	title: 'Over ons',
	route: '/over-ons',
	portal: 'p',
	status: 'published',
	'@self': { updated: '2026-09-29T10:00:00+00:00' },
	body: { type: 'grid', widgets: [{ id: 'intro', widgetKey: 'markdown', gridX: 0, gridY: 0, gridWidth: 12, gridHeight: 2, props: {} }] },
}

/**
 * An editor whose store answers or fails as asked.
 *
 * @param {object} answers `get` and `put`: a value to answer, or an Error to throw.
 * @return {object} The editor.
 */
function editorOver(answers) {
	const saver = createPageSaver({
		get: async () => {
			if (answers.get instanceof Error) {
				throw answers.get
			}
			return { data: JSON.parse(JSON.stringify(answers.get)) }
		},
		put: async (url, payload) => {
			if (answers.put instanceof Error) {
				throw answers.put
			}
			return { data: { ...payload, '@self': { updated: '2026-09-29T12:00:00+00:00' } } }
		},
		url: (id) => `/objects/page/${id}`,
	})
	return createPageEditor({ saver, pageId: 'p1', t })
}

/**
 * @param {number} status The answer's status.
 * @return {Error} A refused request.
 */
function refused(status) {
	const error = new Error(`HTTP ${status}`)
	error.response = { status }
	return error
}

test('a load fills the state and drops a selection the page no longer holds', async () => {
	const draft = { type: 'grid', widgets: [{ ...PAGE.body.widgets[0], id: 'd1' }] }
	const editor = editorOver({ get: { ...PAGE, draftBody: draft } })
	editor.state.selectedId = 'intro'
	await editor.load()
	const { page, version, kind, markdown, hasDraft, dirty, selectedId, loading, error, conflict } = editor.state
	assert.deepEqual(
		{ title: page.title, version, kind, markdown, hasDraft, dirty, selectedId, loading, error, conflict },
		{ title: 'Over ons', version: '2026-09-29T10:00:00+00:00', kind: 'grid', markdown: '', hasDraft: true, dirty: false, selectedId: '', loading: false, error: '', conflict: null },
	)
	assert.deepEqual(editor.state.widgets.map((w) => w.id), ['d1'])

	const plain = editorOver({ get: PAGE })
	plain.state.selectedId = 'intro'
	await plain.load()
	assert.equal(plain.state.selectedId, 'intro', 'a selection the page holds stays')
	assert.equal(plain.state.hasDraft, false)
	assert.deepEqual(plain.state.widgets.map((w) => w.id), ['intro'])
})

test('a failed load says why', async () => {
	const cases = [
		[refused(403), 'You are not allowed to edit portal pages. An administrator sets the editor groups in the Portaliq admin settings.'],
		[refused(401), 'You are not allowed to edit portal pages. An administrator sets the editor groups in the Portaliq admin settings.'],
		[refused(404), 'This page no longer exists.'],
		[refused(500), 'This page could not be loaded.'],
		[new Error('offline'), 'This page could not be loaded.'],
	]
	for (const [failure, message] of cases) {
		const editor = editorOver({ get: failure })
		await editor.load()
		assert.equal(editor.state.error, message, failure.message)
		assert.equal(editor.state.loading, false)
	}
})

test('a failed save says why and keeps the work', async () => {
	for (const [failure, message] of [
		[refused(404), 'This page no longer exists.'],
		[refused(500), 'Saving failed. Nothing was changed.'],
	]) {
		const editor = editorOver({ get: PAGE, put: failure })
		await editor.load()
		editor.removeWidget('intro')
		await editor.saveDraft()
		assert.equal(editor.state.error, message)
		assert.equal(editor.state.conflict, null)
		assert.equal(editor.state.saving, false)
		assert.equal(editor.state.notice, '')
		assert.equal(editor.state.dirty, true)
	}
})

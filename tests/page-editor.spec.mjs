#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// page-editor.spec.mjs: the shared page editor core in src/editor/
// (portal-in-place-editing A1, REQ-PIE-001 to REQ-PIE-005).
//
// The core is plain JavaScript over injected HTTP calls, so this runs it
// without Vue and without a server. Every payload the editor would write is
// validated against the REAL `page` schema fragment from
// lib/Settings/portaliq_register.json, because a payload the schema refuses
// passes every unit test and fails on the first live save.
//
// Usage:
//   node --test tests/page-editor.spec.mjs

import Ajv from 'ajv'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	createEditHistory,
	createPageEditor,
	createPageSaver,
	HISTORY_LIMIT,
	historyIntent,
	inspectorModeFor,
	propsFromFormContent,
	readBody,
	sharedFormFor,
} from '../src/editor/index.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const register = JSON.parse(
	readFileSync(join(ROOT, 'lib', 'Settings', 'portaliq_register.json'), 'utf8'),
)
const pageSchema = register.components.schemas.page
const ajv = new Ajv({ strict: false, allErrors: true })
const validatePage = ajv.compile({
	type: 'object',
	required: pageSchema.required,
	properties: pageSchema.properties,
})

/**
 * Assert a payload is one the real page schema accepts.
 *
 * @param {object} payload The object the editor would PUT.
 * @return {void}
 */
function assertValidPage(payload) {
	const ok = validatePage(payload)
	assert.ok(ok, JSON.stringify(validatePage.errors))
}

function t(_app, text, vars = {}) {
	return text.replace(/\{(\w+)\}/g, (_m, key) =>
		key in vars ? String(vars[key]) : `{${key}}`,
	)
}

const BASE = {
	title: 'Over ons',
	route: '/over-ons',
	portal: 'gemeente-voorbeeld',
	status: 'published',
}

/**
 * An in-memory OpenRegister for one page object.
 *
 * @param {object} stored The page as stored, with `@self.updated`.
 * @return {object} The transport, the stored object and the calls made.
 */
function fakeStore(stored) {
	const store = { object: stored, puts: [], gets: 0, answerPut: null }
	store.get = async () => {
		store.gets += 1
		return { data: JSON.parse(JSON.stringify(store.object)) }
	}
	store.put = async (url, payload, config) => {
		store.puts.push({ url, payload, config })
		if (store.answerPut) {
			throw store.answerPut
		}
		store.object = {
			...JSON.parse(JSON.stringify(payload)),
			'@self': { updated: '2026-09-29T12:00:00+00:00' },
		}
		return { data: store.object }
	}
	return store
}

/**
 * An editor over a fake store.
 *
 * @param {object} stored The stored page.
 * @return {Promise<{editor: object, store: object}>} Loaded.
 */
async function editorFor(stored) {
	const store = fakeStore(stored)
	const saver = createPageSaver({
		get: store.get,
		put: store.put,
		url: (id) => `/apps/openregister/api/objects/portaliq/page/${id}`,
	})
	const editor = createPageEditor({ saver, pageId: 'p1', t })
	await editor.load()
	return { editor, store }
}

const GRID_PAGE = {
	...BASE,
	'@self': { updated: '2026-09-29T10:00:00+00:00' },
	body: {
		type: 'grid',
		widgets: [
			{
				id: 'intro',
				widgetKey: 'markdown',
				gridX: 0,
				gridY: 0,
				gridWidth: 12,
				gridHeight: 4,
				props: { markdown: 'Hallo' },
			},
			{
				id: 'hero-1',
				widgetKey: 'hero',
				gridX: 0,
				gridY: 4,
				gridWidth: 12,
				gridHeight: 5,
				props: {},
			},
		],
	},
}

const MARKDOWN_PAGE = {
	...BASE,
	'@self': { updated: '2026-09-29T10:00:00+00:00' },
	body: { type: 'markdown', markdown: '## Over ons' },
}

// REQ-PIE-001 ---------------------------------------------------------------

test('publishing a markdown page keeps its markdown', async () => {
	const { editor, store } = await editorFor(MARKDOWN_PAGE)
	await editor.publish()

	assert.equal(store.puts.length, 1)
	const payload = store.puts[0].payload
	assert.deepEqual(payload.body, { type: 'markdown', markdown: '## Over ons' })
	assert.equal('draftBody' in payload, false)
	assert.equal('@self' in payload, false)
	assertValidPage(payload)
})

test('a markdown draft is published as markdown and a draft save keeps the type', async () => {
	const { editor, store } = await editorFor({
		...MARKDOWN_PAGE,
		draftBody: { type: 'markdown', markdown: '## Nieuw' },
	})
	await editor.saveDraft()
	assert.deepEqual(store.puts[0].payload.draftBody, {
		type: 'markdown',
		markdown: '## Nieuw',
	})
	assert.deepEqual(store.puts[0].payload.body, {
		type: 'markdown',
		markdown: '## Over ons',
	})
	assertValidPage(store.puts[0].payload)

	await editor.publish()
	assert.deepEqual(store.puts[1].payload.body, {
		type: 'markdown',
		markdown: '## Nieuw',
	})
	assertValidPage(store.puts[1].payload)
})

test('a markdown page opens as markdown and refuses grid edits', async () => {
	const { editor } = await editorFor(MARKDOWN_PAGE)
	assert.equal(editor.state.kind, 'markdown')
	assert.equal(editor.state.markdown, '## Over ons')

	editor.addWidget('markdown')
	editor.removeWidget('anything')
	assert.deepEqual(editor.state.widgets, [])
	assert.equal(editor.state.dirty, false)
	assert.equal(editor.state.canUndo, false)
})

test('discarding a draft of a markdown page leaves its body as it was', async () => {
	const { editor, store } = await editorFor({
		...MARKDOWN_PAGE,
		draftBody: { type: 'markdown', markdown: '## Nieuw' },
	})
	await editor.discard()
	assert.deepEqual(store.puts[0].payload.body, {
		type: 'markdown',
		markdown: '## Over ons',
	})
	assert.equal('draftBody' in store.puts[0].payload, false)
})

test('readBody takes the draft first and reads a missing body as an empty grid', () => {
	assert.equal(
		readBody({
			body: { type: 'markdown', markdown: 'a' },
			draftBody: { type: 'grid', widgets: [] },
		}).kind,
		'grid',
	)
	assert.deepEqual(readBody({}), {
		kind: 'grid',
		widgets: [],
		markdown: '',
		fromDraft: false,
	})
})

// REQ-PIE-002 ---------------------------------------------------------------

test('an added widget lands below everything and is selected', async () => {
	const { editor } = await editorFor(GRID_PAGE)
	editor.addWidget('markdown')

	const added = editor.state.widgets[2]
	assert.equal(added.widgetKey, 'markdown')
	assert.equal(added.gridY, 9)
	assert.equal(added.gridX, 0)
	assert.equal(editor.state.selectedId, added.id)
	assert.equal(added.id, 'markdown-1')
	assert.equal(editor.state.dirty, true)
})

test('a grid publish writes the widgets as a grid body the schema accepts', async () => {
	const { editor, store } = await editorFor(GRID_PAGE)
	editor.applyLayout([
		{ id: 'intro', gridX: 6, gridY: 0, gridWidth: 6, gridHeight: 4 },
	])
	await editor.publish()

	const body = store.puts[0].payload.body
	assert.equal(body.type, 'grid')
	assert.equal(body.widgets[0].gridX, 6)
	assert.deepEqual(body.widgets[0].props, { markdown: 'Hallo' })
	assertValidPage(store.puts[0].payload)
})

test('a layout change that moves nothing is not a change', async () => {
	const { editor } = await editorFor(GRID_PAGE)
	editor.applyLayout(GRID_PAGE.body.widgets)
	assert.equal(editor.state.dirty, false)
	assert.equal(editor.state.canUndo, false)
})

test('the designer delegates to the editor core', () => {
	const source = readFileSync(
		join(ROOT, 'src', 'views', 'PageLayoutDesigner.vue'),
		'utf8',
	)
	assert.match(source, /from '\.\.\/editor\/index\.js'/)
	assert.match(source, /createPageEditor\(/)
	assert.match(source, /<PageGridEditor/)
	// No body payload of its own: the destroying write came from exactly this.
	assert.doesNotMatch(source, /type: 'grid'/)
	assert.doesNotMatch(source, /draftBody/)
})

test('nothing outside src/editor imports an editor file other than its index', () => {
	for (const file of ['src/views/PageLayoutDesigner.vue']) {
		const source = readFileSync(join(ROOT, file), 'utf8')
		// PageGridEditor.vue is the one exception: node cannot load a .vue
		// file, so index.js cannot re-export it.
		const deep = source.match(
			/from '[^']*\/editor\/(?!index\.js'|PageGridEditor\.vue')[^']+'/g,
		)
		assert.equal(deep, null, `${file} imports ${deep}`)
	}
})

// REQ-PIE-003 ---------------------------------------------------------------

const TextForm = { name: 'CnTextWidgetForm' }
const REGISTRY = {
	text: { form: TextForm, defaultContent: { text: '' } },
	divider: { renderer: {} },
}

test('a key with a shared form is configured through it', async () => {
	assert.equal(sharedFormFor('text', REGISTRY), TextForm)
	assert.equal(
		inspectorModeFor('text', {
			registry: REGISTRY,
			isPublic: () => false,
			fields: [],
		}),
		'form',
	)

	const { editor } = await editorFor({
		...GRID_PAGE,
		body: {
			type: 'grid',
			widgets: [
				{
					id: 't1',
					widgetKey: 'text',
					gridX: 0,
					gridY: 0,
					gridWidth: 6,
					gridHeight: 3,
					props: { text: 'Welkom' },
				},
			],
		},
	})
	editor.select('t1')
	editor.replaceProps(
		propsFromFormContent({ text: 'Welkom thuis', contentMode: 'markdown' }),
	)
	assert.deepEqual(editor.state.widgets[0].props, {
		text: 'Welkom thuis',
		contentMode: 'markdown',
	})
})

test('a key without a form falls back to fields, and without fields to JSON', () => {
	assert.equal(sharedFormFor('hero', REGISTRY), null)
	assert.equal(
		inspectorModeFor('hero', {
			registry: REGISTRY,
			isPublic: () => true,
			fields: [{ name: 'title' }],
		}),
		'fields',
	)
	assert.equal(
		inspectorModeFor('divider', {
			registry: REGISTRY,
			isPublic: () => false,
			fields: [],
		}),
		'json',
	)
	// A public block never borrows a dashboard form, even under the same key:
	// the site renders it with a different component.
	assert.equal(
		inspectorModeFor('text', {
			registry: REGISTRY,
			isPublic: () => true,
			fields: [],
		}),
		'json',
	)
})

// REQ-PIE-004 ---------------------------------------------------------------

test('undo and redo a removal', async () => {
	const { editor } = await editorFor(GRID_PAGE)
	editor.removeWidget('hero-1')
	assert.equal(editor.state.widgets.length, 1)

	editor.undo()
	assert.deepEqual(
		editor.state.widgets.map((w) => w.id),
		['intro', 'hero-1'],
	)
	assert.equal(editor.state.canRedo, true)

	editor.redo()
	assert.deepEqual(
		editor.state.widgets.map((w) => w.id),
		['intro'],
	)
})

test('typing in one field is one step, and a new change drops the redo branch', async () => {
	const { editor } = await editorFor(GRID_PAGE)
	editor.select('intro')
	editor.setProp('markdown', 'H')
	editor.setProp('markdown', 'He')
	editor.setProp('markdown', 'Hey')
	editor.undo()
	assert.equal(editor.state.widgets[0].props.markdown, 'Hallo')
	assert.equal(editor.state.canUndo, false)

	editor.redo()
	editor.undo()
	editor.addWidget('hero')
	assert.equal(editor.state.canRedo, false)
})

test('the history holds 50 steps', () => {
	assert.equal(HISTORY_LIMIT, 50)
	const history = createEditHistory()
	for (let i = 0; i < 60; i++) {
		history.record({ n: i })
	}
	let undone = 0
	let current = { n: 60 }
	while (history.canUndo()) {
		current = history.undo(current)
		undone += 1
	}
	assert.equal(undone, 50)
	assert.deepEqual(current, { n: 10 })
})

test('the keyboard intent: Ctrl+Z, Ctrl+Shift+Z, Ctrl+Y, and not inside a text field', () => {
	const key = (over) => ({
		key: 'z',
		ctrlKey: true,
		metaKey: false,
		shiftKey: false,
		target: { tagName: 'DIV' },
		...over,
	})
	assert.equal(historyIntent(key({})), 'undo')
	assert.equal(historyIntent(key({ ctrlKey: false, metaKey: true })), 'undo')
	assert.equal(historyIntent(key({ shiftKey: true, key: 'Z' })), 'redo')
	assert.equal(historyIntent(key({ key: 'y' })), 'redo')
	assert.equal(historyIntent(key({ ctrlKey: false })), null)
	assert.equal(historyIntent(key({ target: { tagName: 'TEXTAREA' } })), null)
	assert.equal(
		historyIntent(key({ target: { tagName: 'INPUT', type: 'text' } })),
		null,
	)
	assert.equal(
		historyIntent(key({ target: { tagName: 'DIV', isContentEditable: true } })),
		null,
	)
})

test('a load starts a new history', async () => {
	const { editor } = await editorFor(GRID_PAGE)
	editor.removeWidget('hero-1')
	await editor.load()
	assert.equal(editor.state.canUndo, false)
})

// REQ-PIE-005 ---------------------------------------------------------------

test('the save sends the loaded version as If-Match', async () => {
	const { editor, store } = await editorFor(GRID_PAGE)
	editor.removeWidget('hero-1')
	await editor.saveDraft()
	assert.equal(
		store.puts[0].config.headers['If-Match'],
		'2026-09-29T10:00:00+00:00',
	)
	assert.equal(editor.state.version, '2026-09-29T12:00:00+00:00')
	assert.equal(editor.state.error, '')
	assertValidPage(store.puts[0].payload)
})

test('a save after someone else saved is refused before the write', async () => {
	const { editor, store } = await editorFor(GRID_PAGE)
	editor.removeWidget('hero-1')
	store.object = {
		...store.object,
		'@self': { updated: '2026-09-29T10:05:00+00:00' },
	}

	await editor.saveDraft()
	assert.equal(store.puts.length, 0)
	assert.ok(editor.state.conflict)
	assert.equal(editor.state.conflict.changedAt, '2026-09-29T10:05:00+00:00')
	assert.match(editor.state.error, /someone else/i)
	// The unsaved work stays on screen.
	assert.deepEqual(
		editor.state.widgets.map((w) => w.id),
		['intro'],
	)
	assert.equal(editor.state.dirty, true)
})

test('a 409 from the store is a conflict', async () => {
	const { editor, store } = await editorFor(GRID_PAGE)
	const refusal = new Error('HTTP 409')
	refusal.response = {
		status: 409,
		data: { currentUpdated: '2026-09-29T10:06:00+00:00' },
	}
	store.answerPut = refusal

	await editor.publish()
	assert.equal(editor.state.conflict.changedAt, '2026-09-29T10:06:00+00:00')
	assert.match(editor.state.error, /someone else/i)
})

test('a refusal is named as one', async () => {
	const { editor, store } = await editorFor(GRID_PAGE)
	const refusal = new Error('HTTP 403')
	refusal.response = { status: 403 }
	store.answerPut = refusal
	await editor.saveDraft()
	assert.equal(editor.state.conflict, null)
	assert.match(editor.state.error, /not allowed/)
})

// THE EDITOR NAMES A BLOCK BY ITS WIDGET'S NAME (resident-sees-words-not-codes).
// The cell bar, the grid item's name and the inspector showed the raw key
// ("markdown"); the palette already named it "Tekst".
// @spec openspec/changes/resident-sees-words-not-codes/specs/portal-in-place-editing/spec.md#requirement-the-editor-names-a-block-by-its-widgets-name

test('a widget reads by its name: the public label, the registry name, else the key in words', async () => {
	const { widgetLabel } = await import('../src/lib/widgetLabels.js')
	assert.equal(widgetLabel('markdown', {}), 'Tekst')
	assert.equal(widgetLabel('publicationDetail', {}), 'Publicatiedetail')
	assert.equal(widgetLabel('kpiCards', { kpiCards: { displayName: 'Kerncijfers' } }), 'Kerncijfers')
	assert.equal(widgetLabel('myOwnWidget', {}), 'My Own Widget')
})

test('the editor shows the widget name, never the raw key, in the cell bar, the item name and the inspector', () => {
	const source = readFileSync(join(ROOT, 'src', 'editor', 'PageGridEditor.vue'), 'utf8')
	assert.doesNotMatch(source, /cell-key">\{\{\s*item\.widgetKey\s*\}\}/)
	assert.doesNotMatch(source, /<code>\{\{ selected\.widgetKey \}\}<\/code>/)
	assert.doesNotMatch(source, /key: item\.widgetKey/)
	assert.match(source, /widgetLabel\(/)
	const catalogue = readFileSync(join(ROOT, 'src', 'lib', 'pageWidgetCatalogue.js'), 'utf8')
	assert.match(catalogue, /label: widgetLabel\(key, dashboardWidgetRegistry\)/)
})

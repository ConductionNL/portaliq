#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// page-editor-regions.spec.mjs: the shared page editor keeps the regions it
// does not edit (portal-theme-blocks-and-contributed-pages task 8, REQ-PTB-010).
//
// The editor core in src/editor/ serves both the admin designer and the portal
// edit mode, so proving it here proves both. The grid shows only `main`
// widgets; every save writes the widgets of the other regions, and
// `clearedRegions`, back exactly as they were. Every payload is validated
// against the REAL `page` schema fragment from lib/Settings/portaliq_register.json.
//
// Usage:
//   node --test tests/page-editor-regions.spec.mjs

import Ajv from 'ajv'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	bodyFor,
	createPageEditor,
	createPageSaver,
	readBody,
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

function assertValidPage(payload) {
	assert.ok(validatePage(payload), JSON.stringify(validatePage.errors))
}

function t(_app, text, vars = {}) {
	return text.replace(/\{(\w+)\}/g, (_m, key) =>
		key in vars ? String(vars[key]) : `{${key}}`,
	)
}

function fakeStore(stored) {
	const store = { object: stored, puts: [] }
	store.get = async () => ({ data: JSON.parse(JSON.stringify(store.object)) })
	store.put = async (url, payload) => {
		store.puts.push({ url, payload })
		store.object = {
			...JSON.parse(JSON.stringify(payload)),
			'@self': { updated: `2026-09-29T12:00:0${store.puts.length}+00:00` },
		}
		return { data: store.object }
	}
	return store
}

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

const HERO = {
	id: 'hero-1',
	widgetKey: 'hero',
	slot: 'hero',
	gridX: 0,
	gridY: 0,
	gridWidth: 12,
	gridHeight: 5,
	props: {
		title: 'Welkom',
		actions: [{ label: 'Melding doen', href: '/melden' }],
	},
}

const ASIDE = {
	id: 'aside-links',
	widgetKey: 'markdown',
	slot: 'aside',
	gridX: 0,
	gridY: 0,
	gridWidth: 4,
	gridHeight: 3,
	props: { markdown: 'Zie ook' },
}

function mainWidget(id, gridY, slot) {
	return {
		id,
		widgetKey: 'markdown',
		...(slot === undefined ? {} : { slot }),
		gridX: 0,
		gridY,
		gridWidth: 12,
		gridHeight: 4,
		props: { markdown: id },
	}
}

// A hero, three `main` widgets (slot `body`, `main` and none: all three mean
// main) and a page that empties `aside` on purpose.
const REGION_PAGE = {
	title: 'Home',
	route: '/',
	portal: 'gemeente-voorbeeld',
	status: 'published',
	'@self': { updated: '2026-09-29T10:00:00+00:00' },
	body: {
		type: 'grid',
		widgets: [
			HERO,
			mainWidget('main-a', 0, 'body'),
			mainWidget('main-b', 4, 'main'),
			mainWidget('main-c', 8),
		],
		clearedRegions: ['aside'],
	},
}

function heroOf(body) {
	return body.widgets.find((w) => w.id === 'hero-1')
}

// REQ-PTB-010 ---------------------------------------------------------------

test('the designer grid shows only the main widgets', async () => {
	const { editor } = await editorFor(REGION_PAGE)
	assert.deepEqual(
		editor.state.widgets.map((w) => w.id),
		['main-a', 'main-b', 'main-c'],
	)
	assert.deepEqual(
		readBody({ body: REGION_PAGE.body }).widgets.map((w) => w.id),
		['main-a', 'main-b', 'main-c'],
	)
})

test('moving a main widget, saving a draft and publishing keeps the hero and clearedRegions', async () => {
	const { editor, store } = await editorFor(REGION_PAGE)
	editor.applyLayout([
		{ id: 'main-a', gridX: 0, gridY: 8, gridWidth: 12, gridHeight: 4 },
		{ id: 'main-c', gridX: 0, gridY: 0, gridWidth: 12, gridHeight: 4 },
	])

	await editor.saveDraft()
	const draft = store.puts[0].payload
	assertValidPage(draft)
	assert.deepEqual(heroOf(draft.draftBody), HERO)
	assert.deepEqual(draft.draftBody.clearedRegions, ['aside'])
	assert.equal(draft.draftBody.widgets.find((w) => w.id === 'main-a').gridY, 8)
	// The live body is not touched by a draft save.
	assert.deepEqual(draft.body, REGION_PAGE.body)

	await editor.publish()
	const published = store.puts[1].payload
	assertValidPage(published)
	assert.equal(published.draftBody, undefined)
	assert.deepEqual(heroOf(published.body), HERO)
	assert.deepEqual(published.body.clearedRegions, ['aside'])
	assert.equal(published.body.widgets.length, 4)
	const moved = published.body.widgets.find((w) => w.id === 'main-a')
	assert.equal(moved.gridY, 8)
	assert.equal(moved.slot, 'body')
	assert.equal(published.body.widgets.find((w) => w.id === 'main-b').slot, 'main')
})

test('discarding a draft keeps the published regions as they were', async () => {
	const withDraft = {
		...REGION_PAGE,
		draftBody: {
			type: 'grid',
			widgets: [HERO, mainWidget('main-a', 0, 'body')],
			clearedRegions: ['footer'],
		},
	}
	const { editor, store } = await editorFor(withDraft)
	await editor.discard()
	const payload = store.puts[0].payload
	assertValidPage(payload)
	assert.equal(payload.draftBody, undefined)
	assert.deepEqual(payload.body, REGION_PAGE.body)
})

test('a draft with its own regions is the source the save keeps them from', async () => {
	const withDraft = {
		...REGION_PAGE,
		draftBody: {
			type: 'grid',
			widgets: [ASIDE, mainWidget('main-a', 0, 'body')],
			clearedRegions: ['hero'],
		},
	}
	const { editor, store } = await editorFor(withDraft)
	assert.deepEqual(
		editor.state.widgets.map((w) => w.id),
		['main-a'],
	)
	editor.removeWidget('main-a')
	await editor.publish()

	const body = store.puts[0].payload.body
	assertValidPage(store.puts[0].payload)
	assert.deepEqual(body.widgets, [ASIDE])
	assert.deepEqual(body.clearedRegions, ['hero'])
})

test('an added widget lands in main, below the main widgets only', async () => {
	const { editor, store } = await editorFor(REGION_PAGE)
	editor.addWidget('markdown')
	const added = editor.state.widgets[3]
	assert.equal(added.gridY, 12)
	await editor.saveDraft()
	const widgets = store.puts[0].payload.draftBody.widgets
	assert.equal(widgets.length, 5)
	assert.equal(widgets.find((w) => w.id === added.id).slot, 'body')
	assert.deepEqual(heroOf(store.puts[0].payload.draftBody), HERO)
})

test('bodyFor keeps a widget whose slot names no region, and never drops body keys', () => {
	const odd = { ...ASIDE, id: 'odd', slot: 'sidebar-legacy' }
	const state = {
		kind: 'grid',
		page: {
			body: {
				type: 'grid',
				widgets: [odd, mainWidget('main-a', 0, 'body')],
				clearedRegions: ['hero'],
			},
		},
		widgets: [],
	}
	const body = bodyFor(state)
	assert.deepEqual(body.widgets, [odd])
	assert.deepEqual(body.clearedRegions, ['hero'])
	assert.equal(body.type, 'grid')
})

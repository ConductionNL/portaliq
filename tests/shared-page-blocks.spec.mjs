#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// shared-page-blocks.spec.mjs: the portals of one organisation share page
// blocks (site-shared-page-blocks). The page editor edits a block through an
// adapter that translates `widgets` and `draftWidgets` to a page's `body` and
// `draftBody`; the public renderer draws a placement as a nested grid and
// draws nothing for a block that is unavailable.
//
// Usage:
//   node --test tests/shared-page-blocks.spec.mjs
//
// @spec openspec/changes/site-shared-page-blocks/specs/portal-shared-page-blocks/spec.md

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { blockToPage, pageToBlock } from '../src/editor/blockSaver.js'
import { createPageSaver } from '../src/editor/pageSaver.js'

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8')

const WIDGET = { id: 'w1', widgetKey: 'markdown', gridX: 0, gridY: 0, gridWidth: 12, gridHeight: 2, props: { markdown: 'Open van negen tot vijf' } }
const STORED = {
	'@self': { id: 'b1', updated: '2026-10-08T10:00:00Z' },
	title: 'Contact en openingstijden',
	organisation: 'gemeente-voorbeeld',
	status: 'published',
	widgets: [WIDGET],
}

test('a block reads as a page with a grid body and no draft', () => {
	const page = blockToPage(STORED)
	assert.deepEqual(page.body, { type: 'grid', widgets: [WIDGET] })
	assert.equal('draftBody' in page, false)
	assert.equal('widgets' in page, false)
	assert.equal(page.title, 'Contact en openingstijden')
	assert.equal(page['@self'].id, 'b1', 'the version marker survives for the conflict check')
})

test('a block with draft widgets reads as a page with a draft, even an empty one', () => {
	assert.deepEqual(blockToPage({ ...STORED, draftWidgets: [] }).draftBody, { type: 'grid', widgets: [] })
	assert.deepEqual(blockToPage({ ...STORED, draftWidgets: [WIDGET] }).draftBody.widgets, [WIDGET])
})

test('saving a draft stores draftWidgets and leaves the published widgets alone', () => {
	const payload = { ...blockToPage(STORED), draftBody: { type: 'grid', widgets: [{ ...WIDGET, id: 'w2' }] } }
	const block = pageToBlock(payload)
	assert.deepEqual(block.widgets, [WIDGET])
	assert.equal(block.draftWidgets[0].id, 'w2')
	assert.equal('body' in block, false)
	assert.equal('route' in block, false)
	assert.equal('portal' in block, false, 'a block has no portal')
	assert.equal(block.organisation, 'gemeente-voorbeeld')
})

test('publishing stores the new widgets and clears the draft', () => {
	const published = { ...blockToPage(STORED), body: { type: 'grid', widgets: [{ ...WIDGET, id: 'w3' }] } }
	delete published.draftBody
	const block = pageToBlock(published)
	assert.equal(block.widgets[0].id, 'w3')
	assert.equal('draftWidgets' in block, false, 'the key is removed, so the replace clears the draft')
})

test('the page saver edits a block over the adapter and keeps its conflict check', async () => {
	let stored = { ...STORED }
	const puts = []
	const saver = createPageSaver({
		get: async () => ({ data: blockToPage(stored) }),
		put: async (_url, payload, config) => {
			puts.push({ payload: pageToBlock(payload), config })
			stored = { ...stored, ...pageToBlock(payload), '@self': { id: 'b1', updated: '2026-10-08T11:00:00Z' } }
		},
		url: (id) => `/blocks/${id}`,
	})
	const { page, version } = await saver.load('b1')
	assert.equal(page.body.widgets.length, 1)

	await saver.save('b1', { ...page, draftBody: { type: 'grid', widgets: [] } }, version)
	assert.equal(puts[0].config.headers['If-Match'], version)
	assert.deepEqual(puts[0].payload.draftWidgets, [])

	await assert.rejects(saver.save('b1', page, version), /changed since it was loaded/)
})

test('a placement draws its widgets as a nested grid, and nothing when unavailable', () => {
	// The component imports the renderer it nests, which a node test cannot
	// compile, so the rule is read from the source.
	const source = read('src/site/components/SharedBlock.vue')
	assert.match(source, /<div\s+v-if="shown"/, 'the wrapper is drawn only when shown')
	assert.match(source, /return !this\.unavailable && this\.widgets\.length > 0/)
	assert.match(source, /<WidgetGrid v-bind="host" :widgets="widgets" \/>/, 'same renderer, same context')
})

test('the public renderer, the catalogue and the designer all know the block', () => {
	const grid = read('src/site/components/WidgetGrid.vue')
	assert.match(grid, /\n\tsharedBlock: SharedBlock,/)
	assert.match(grid, /import\('\.\/SharedBlock\.vue'\)/, 'the nested grid is loaded on demand, not in the entry')
	assert.match(grid, /widget\.widgetKey === 'sharedBlock'\) \{\s*return \{ \.\.\.props, host:/)

	const catalogue = read('src/lib/pageWidgetCatalogue.js')
	assert.match(catalogue, /sharedBlock: \['widgets', 'unavailable', 'host'\]/, 'the content API fills what an author cannot')
	assert.match(catalogue, /sharedBlock: \[\{ name: 'block', kind: 'block'/)

	const designer = read('src/views/PageLayoutDesigner.vue')
	assert.match(designer, /this\.\$route\?\.name === 'SharedBlockLayout'/)
	assert.match(designer, /portaliq\/\$\{schema\}\//)

	const manifest = JSON.parse(read('src/manifest.json'))
	const layout = manifest.pages.find((page) => page.id === 'SharedBlockLayout')
	assert.equal(layout.route, '/shared-blocks/:id/layout')
	assert.equal(layout.component, 'PageLayoutDesigner')
	const index = manifest.pages.find((page) => page.id === 'SharedBlocks')
	assert.equal(index.config.schema, 'sharedBlock')
	assert.equal(index.config.actions[0].target, 'SharedBlockLayout')
})

test('the register holds the block with an organisation and no portal', () => {
	const register = JSON.parse(read('lib/Settings/portaliq_register.json'))
	const block = register.components.schemas.sharedBlock
	assert.deepEqual(block.required, ['title', 'organisation'])
	assert.equal('portal' in block.properties, false)
	for (const key of ['title', 'description', 'organisation', 'status', 'widgets', 'draftWidgets']) {
		assert.ok(block.properties[key], `${key} is declared`)
	}
	assert.deepEqual(block.properties.status.enum, ['draft', 'published'])
	assert.ok(register.components.registers.portaliq.schemas.includes('sharedBlock'))
	assert.ok(block.authorization.read.every((rule) => rule !== 'public'), 'a block is not publicly readable; the content API serves it')
})

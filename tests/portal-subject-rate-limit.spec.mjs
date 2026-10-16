// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// portal-subject-rate-limit: a collection whose read failed (a 429 under the
// old per-IP limit, a 5xx) says so on the page instead of drawing an empty
// block, and Enter in a widget's search field searches for the typed words,
// not for "[object Event]" (portal-proof run 3, De Wilgenboom).
//
// Usage:
//   node --test tests/portal-subject-rate-limit.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { loadSfc, renderSfc } from './support/render-sfc.mjs'

// The library's public entry imports `.vue` files node cannot load; the grid
// needs only these names from it.
const LIBRARY_STUB = {
	'@conduction/nextcloud-vue/public': [
		'export const siteBlockIsBand = () => false',
		'export const siteBlockRegistry = {}',
		'export const CnSiteIcon = {}',
		'export const CnSiteSearch = {}',
		'export const CnSiteSection = {}',
	].join('\n'),
	'@conduction/nextcloud-vue': "export const cnRenderMarkdown = () => ''\n",
}

const contribution = {
	app: 'learniq',
	collections: [
		{
			id: 'parentHomework',
			label: 'Homework',
			register: 'learniq',
			schema: 'homework',
			columns: [{ field: 'title', label: 'Title' }],
		},
	],
	actions: [],
	pages: [],
}

test('a collection whose read failed says so, never an empty table', async () => {
	const page = {
		id: 'p',
		blocks: [{ type: 'collection', collection: 'parentHomework' }],
	}
	const failed = await renderSfc(
		'src/site/pages/collections/ContributionPage.vue',
		{
			page,
			contribution,
			api: {},
			t: (key) => key,
			locale: 'nl',
			initialData: {
				parentHomework: { loading: false, failed: true, objects: [] },
			},
		},
	)
	assert.match(failed, /data-testid="mijn-load-error"/)
	assert.match(failed, /De inhoud kon niet worden geladen\./)
	assert.match(failed, /Opnieuw proberen/)
	assert.doesNotMatch(failed, /data-testid="collection-table"/)

	const loaded = await renderSfc(
		'src/site/pages/collections/ContributionPage.vue',
		{
			page,
			contribution,
			api: {},
			t: (key) => key,
			locale: 'nl',
			initialData: {
				parentHomework: {
					loading: false,
					objects: [{ id: 'h1', title: 'Rekenen' }],
				},
			},
		},
	)
	assert.doesNotMatch(loaded, /mijn-load-error/)
	assert.match(loaded, /data-testid="collection-table"/)
})

test("the grid hands on a widget's search only when it is the typed words", async () => {
	const grid = await loadSfc('src/site/components/WidgetGrid.vue', LIBRARY_STUB)
	const emitted = []
	const host = { $emit: (name, value) => emitted.push([name, value]) }

	// What Chrome fires on Enter in an <input type="search">, reaching a
	// widget's root through the listener that falls through to it.
	grid.methods.forwardSearch.call(host, { type: 'search', target: {} })
	assert.deepEqual(emitted, [], 'a browser event is never a search term')

	grid.methods.forwardSearch.call(host, 'brug')
	assert.deepEqual(emitted, [['search', 'brug']])
})

test('every widget slot in the grid forwards its search through that check', async () => {
	const { readFileSync } = await import('node:fs')
	const source = readFileSync('src/site/components/WidgetGrid.vue', 'utf8')
	assert.doesNotMatch(source, /@search="\$emit\('search', \$event\)"/)
	assert.equal((source.match(/@search="forwardSearch"/g) || []).length, 2)
})

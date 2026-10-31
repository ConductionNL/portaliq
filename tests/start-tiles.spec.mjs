#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// start-tiles.spec.mjs: the start tiles widget (site-nlds-widget-palette
// T10, design D6) reads the portal's public tiles and draws them as the Home
// board's "Direct regelen" list: one link per action, to its page.
//
// Usage:
//   node --test tests/start-tiles.spec.mjs
//
// @spec openspec/changes/site-nlds-widget-palette/specs/portal-contribution-contract/spec.md#requirement-an-action-may-offer-itself-as-a-start-tile-with-a-summary-and-its-audiences-req-snw-020

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { fetchStartTiles, tilesToTasks } from '../src/site/lib/startTiles.js'
import { loaders, metas } from '../src/site/widgets/index.js'

const TILES = [
	{
		label: 'Bezwaar maken',
		summary: 'Maak binnen zes weken bezwaar.',
		audiences: ['citizen'],
		route: '/mijn/dossiq/bezwaar',
	},
	{
		label: 'Klacht indienen',
		summary: 'Niet tevreden?',
		audiences: [],
		route: '/mijn/dossiq/klacht',
	},
	{ label: '', summary: 'Zonder naam', audiences: [], route: '/mijn/x/y' },
	{
		label: 'Elders',
		summary: 'Buiten het loket',
		audiences: [],
		route: 'https://example.org',
	},
]

test('each tile is one task linking to its page in the signed-in area', () => {
	assert.deepEqual(tilesToTasks(TILES), [
		{ label: 'Bezwaar maken', href: '/mijn/dossiq/bezwaar' },
		{ label: 'Klacht indienen', href: '/mijn/dossiq/klacht' },
	])
	assert.deepEqual(tilesToTasks(null), [])
})

test('the tiles come from the public start tiles endpoint of the serving portal', async () => {
	const calls = []
	const tiles = await fetchStartTiles(
		'zuiddrecht',
		async (url) => {
			calls.push(String(url))
			return { ok: true, json: async () => ({ tiles: TILES }) }
		},
		'/index.php/apps/portaliq/api/content',
	)
	assert.equal(tiles.length, 4)
	assert.match(calls[0], /\/api\/content\/start-tiles\?portal=zuiddrecht$/)

	const none = await fetchStartTiles(
		'zuiddrecht',
		async () => ({ ok: false, status: 503 }),
		'/x',
	)
	assert.deepEqual(none, [], 'a failed read draws no tiles')
})

test('the widget is registered once, public, in the navigation group', () => {
	assert.equal(metas.nlStartTiles.scope, 'public')
	assert.equal(metas.nlStartTiles.group, 'nav')
	assert.equal(typeof loaders.nlStartTiles, 'function')
	const grid = readFileSync(
		new URL('../src/site/components/WidgetGrid.vue', import.meta.url),
		'utf8',
	)
	assert.match(grid, /'nlStartTiles'/, 'the grid hands the widget its portal')
})

#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-grid.spec.mjs: the hero block and where a page's widgets land
// (portal-theme-blocks-and-contributed-pages task 6, REQ-PTB-006).
//
// `gridY` is absolute over the whole page, but a full-bleed band is pulled out
// of the grid into its own run. A run that kept absolute rows opened with
// empty rows reserved for the band: a 320px void on the La Franken landing
// page on the reference branch (46d7e9f).
//
// Usage:
//   node --test tests/site-grid.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { heroActions } from '../src/site/lib/blockProps.js'
import { cellStyle, runsFor } from '../src/site/lib/gridPlacement.js'
import { renderSfc } from './support/render-sfc.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')

const LIBRARY = {
	'@conduction/nextcloud-vue/public': [
		"import { h } from 'vue'",
		"export const CnSiteIcon = { props: ['name', 'size'], render() { return h('svg', { 'aria-hidden': 'true' }) } }",
		"export const CnSiteSearch = { props: ['label'], render() { return h('form', { 'aria-label': this.label }) } }",
		"export const CnSiteSection = { render() { return h('section', { class: 'ac-hero' }, this.$slots.default()) } }",
	].join('\n'),
}

const isBand = (key) => key === 'hero'

const LANDING = [
	{ id: 'hero', widgetKey: 'hero', gridY: 0, gridHeight: 4, gridWidth: 12 },
	{ id: 'intro', widgetKey: 'markdown', gridY: 4, gridHeight: 2, gridWidth: 12 },
	{
		id: 'diensten',
		widgetKey: 'contributions',
		gridY: 6,
		gridHeight: 4,
		gridWidth: 12,
	},
]

test('a band leaves no hole in the grid: the run below starts at its own first row', () => {
	const runs = runsFor(LANDING, isBand)

	assert.deepEqual(
		runs.map((run) => run.band),
		[true, false],
	)
	assert.equal(runs[1].widgets.length, 2)
	assert.equal(runs[1].rowOffset, 4)
	assert.deepEqual(cellStyle(runs[1].widgets[0], runs[1].rowOffset), {
		gridColumn: '1 / span 12',
		gridRow: '1 / span 2',
	})
	assert.equal(
		cellStyle(runs[1].widgets[1], runs[1].rowOffset).gridRow,
		'3 / span 4',
	)
	// Without the offset the markdown would open on row 5 of an empty grid.
	assert.equal(cellStyle(runs[1].widgets[0]).gridRow, '5 / span 2')
})

test('bands split the page where the author put them, and geometry is clamped', () => {
	const runs = runsFor(
		[
			{ id: 'a', widgetKey: 'markdown', gridY: 0 },
			{ id: 'b', widgetKey: 'hero', gridY: 2 },
			{ id: 'c', widgetKey: 'markdown', gridY: 6 },
		],
		isBand,
	)
	assert.deepEqual(
		runs.map((run) =>
			run.band ? run.widget.id : run.widgets.map((w) => w.id).join(),
		),
		['a', 'b', 'c'],
	)
	assert.equal(runs[0].rowOffset, 0)
	assert.equal(runs[2].rowOffset, 6)
	assert.equal(cellStyle({ gridX: 10, gridWidth: 6 }).gridColumn, '11 / span 2')
})

test('a third action is not rendered, and an action without a destination is dropped', async () => {
	const actions = [
		{ label: 'Aanvragen', href: '/aanvragen' },
		{ label: 'Zonder bestemming' },
		{ label: 'Script', href: 'javascript:alert(1)' },
		{ label: 'Melden', href: 'https://melden.example' },
		{ label: 'Derde', href: '/derde' },
		{ label: 'Vierde', href: '/vierde' },
	]
	assert.deepEqual(heroActions(actions), [
		{ label: 'Aanvragen', href: '/aanvragen' },
		{ label: 'Melden', href: 'https://melden.example' },
	])

	const html = await renderSfc(
		'src/site/components/HeroBlock.vue',
		{ title: 'Welkom', actions },
		LIBRARY,
	)
	assert.equal(html.split('data-testid="hero-action"').length - 1, 2)
	assert.match(html, /<a class="pq-hero__action" href="\/aanvragen"/)
	assert.doesNotMatch(html, /Derde|javascript:/)
})

test('the eyebrow is not a heading: one heading for the hero, and the icon is hidden', async () => {
	const html = await renderSfc(
		'src/site/components/HeroBlock.vue',
		{ eyebrow: 'Diensten', title: 'Wat wilt u regelen?', titleIcon: 'home' },
		LIBRARY,
	)
	assert.match(
		html,
		/<p class="pq-hero__eyebrow" data-testid="hero-eyebrow">\s*Diensten\s*<\/p>/,
	)
	assert.equal((html.match(/<h[1-6]/g) || []).length, 1)
	assert.match(html, /<h1 class="ac-hero__title">/)
	assert.match(html, /<svg aria-hidden="true">/)
})

test('HeroBlock is registered under the library key, after the spread, so hero stays a band', () => {
	const grid = readFileSync(
		join(ROOT, 'src/site/components/WidgetGrid.vue'),
		'utf8',
	)
	const spread = grid.indexOf('...siteBlockRegistry,')
	const hero = grid.indexOf('hero: HeroBlock,')
	assert.ok(spread > 0 && hero > spread, 'hero: HeroBlock comes after the spread')

	const library = readFileSync(
		join(ROOT, 'node_modules/@conduction/nextcloud-vue/src/public/index.js'),
		'utf8',
	)
	assert.match(library, /SITE_BAND_BLOCKS = \[[^\]]*'hero'/)
	assert.match(grid, /siteBlockIsBand\(key\)/)
})

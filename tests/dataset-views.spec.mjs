#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// dataset-views.spec.mjs: the dataset views on the publication page
// (dataset-views-on-the-public-page, REQ-DSV-003). The answers are the shapes
// opencatalogi's view data endpoint gives (design D3): a chart is `points`,
// a map a GeoJSON FeatureCollection, a table a page of rows.
//
// Usage:
//   node --test tests/dataset-views.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import {
	barGeometry,
	CHART_BOX,
	chartPoints,
	lineGeometry,
	mapPlaces,
	pagesOf,
	pageUrl,
	tablesWithViews,
	viewTable,
} from '../src/site/lib/datasetViews.js'

const nl = JSON.parse(readFileSync(new URL('../src/shared/i18n/nl.json', import.meta.url), 'utf8'))
const en = JSON.parse(readFileSync(new URL('../src/shared/i18n/en.json', import.meta.url), 'utf8'))
function t(key, vars = {}) {
	let text = nl[key] || key
	for (const [name, value] of Object.entries(vars)) {
		text = text.replace(`{${name}}`, String(value))
	}
	return text
}

const barData = {
	kind: 'bar',
	x: 'wijk',
	y: '',
	aggregate: 'count',
	points: [{ x: 'Noord', value: 4 }, { x: 'Zuid', value: 2 }, { x: 'Oost', value: null }],
	truncated: false,
}

test('a reader switches a bar chart to a table: one row per group with its value', () => {
	const table = viewTable({ kind: 'bar', x: 'wijk' }, barData, { t })
	assert.deepEqual(table, {
		columns: ['wijk', 'Aantal'],
		rows: [['Noord', '4'], ['Zuid', '2'], ['Oost', '0']],
	})
})

test('a sum chart names its value column after y', () => {
	const table = viewTable({ kind: 'line' }, { ...barData, kind: 'line', aggregate: 'sum', y: 'bedrag', points: [{ x: '2025', value: 1500.5 }] }, { t })
	assert.deepEqual(table, { columns: ['wijk', 'bedrag'], rows: [['2025', '1500,5']] })
})

test('a map reads as a table of its points, label and coordinates', () => {
	const data = {
		kind: 'map',
		type: 'FeatureCollection',
		features: [
			{ type: 'Feature', geometry: { type: 'Point', coordinates: [5.12, 52.09] }, properties: { label: 'Stadhuis' } },
			{ type: 'Feature', geometry: { type: 'Point', coordinates: [4.9, 52.37] }, properties: {} },
		],
		skipped: 3,
		truncated: false,
	}
	assert.deepEqual(viewTable({ kind: 'map' }, data, { t }), {
		columns: ['Label', 'Breedtegraad', 'Lengtegraad'],
		rows: [['Stadhuis', '52,09', '5,12'], ['', '52,37', '4,9']],
	})
	assert.deepEqual(mapPlaces(data, t), [
		{ name: 'Stadhuis', lat: 52.09, lon: 5.12 },
		{ name: 'Punt 2', lat: 52.37, lon: 4.9 },
	])
})

test('a table view keeps only its own columns, in its order', () => {
	const data = { kind: 'table', columns: ['naam', 'jaar'], results: [{ jaar: 2024, naam: 'A', extra: 'x' }, { naam: 'B' }], total: 2, page: 1, pages: 1 }
	assert.deepEqual(viewTable({ kind: 'table', columns: ['naam', 'jaar'] }, data, { t }), {
		columns: ['naam', 'jaar'],
		rows: [['A', '2024'], ['B', '']],
	})
	assert.equal(pagesOf(data), 1)
	assert.equal(pagesOf({ pages: 4 }), 4)
	assert.equal(pagesOf(null), 1)
})

test('later pages of a table view read the rows endpoint with the page and its size', () => {
	assert.equal(
		pageUrl('/index.php/apps/opencatalogi/api/c/p/tables/t/rows?q=a', 3, 'https://portaal.example'),
		'https://portaal.example/index.php/apps/opencatalogi/api/c/p/tables/t/rows?q=a&_page=3&_limit=20',
	)
	assert.equal(pageUrl('', 2), '')
})

test('only tables with readable views are listed, views in their saved order', () => {
	const publication = {
		tables: [
			{ id: 't1', title: 'Bomen', rowsUrl: '/rows/t1', views: [
				{ id: 'v2', kind: 'map', title: 'Kaart', position: 2, dataUrl: '/d/v2' },
				{ id: 'v1', kind: 'bar', title: 'Per wijk', position: 1, dataUrl: '/d/v1' },
				{ id: 'v3', kind: 'pie', title: 'Onbekend', dataUrl: '/d/v3', position: 3 },
				{ id: 'v4', kind: 'bar', title: 'Zonder adres', dataUrl: '' },
			] },
			{ id: 't2', title: 'Leeg', views: [] },
			null,
		],
	}
	const tables = tablesWithViews(publication)
	assert.equal(tables.length, 1)
	assert.deepEqual(tables[0].views.map((view) => [view.id, view.kind]), [['v1', 'bar'], ['v2', 'map'], ['v3', 'table']])
	assert.equal(tables[0].rowsUrl, '/rows/t1')
	assert.deepEqual(tablesWithViews({}), [])
	assert.deepEqual(tablesWithViews(null), [])
})

test('bars stand on the baseline, the highest reaches the top of the plot', () => {
	const geometry = barGeometry(chartPoints(barData), CHART_BOX)
	assert.equal(geometry.max, 4)
	assert.equal(geometry.baseline, CHART_BOX.height - CHART_BOX.bottom)
	assert.equal(geometry.bars.length, 3)
	assert.equal(geometry.bars[0].y, CHART_BOX.top)
	assert.equal(geometry.bars[0].y + geometry.bars[0].height, geometry.baseline)
	assert.equal(geometry.bars[1].height, geometry.bars[0].height / 2)
	assert.equal(geometry.bars[2].height, 0)
})

test('a line runs through one dot per group, left to right', () => {
	const geometry = lineGeometry([{ x: 'a', value: 0 }, { x: 'b', value: 10 }], CHART_BOX)
	assert.deepEqual(geometry.dots.map((dot) => [dot.x, dot.y]), [
		[CHART_BOX.left, CHART_BOX.height - CHART_BOX.bottom],
		[CHART_BOX.width - CHART_BOX.right, CHART_BOX.top],
	])
	assert.equal(geometry.path, `M${CHART_BOX.left} ${CHART_BOX.height - CHART_BOX.bottom} L${CHART_BOX.width - CHART_BOX.right} ${CHART_BOX.top}`)
})

test('every string the views read is in both bundles', () => {
	const source = readFileSync(new URL('../src/site/components/PublicationDatasetView.vue', import.meta.url), 'utf8')
		+ readFileSync(new URL('../src/site/lib/datasetViews.js', import.meta.url), 'utf8')
	const keys = [...source.matchAll(/\bt\('([^']+)'/g)].map((match) => match[1])
	assert.ok(keys.length > 10)
	for (const key of keys) {
		assert.ok(key in en, `en lacks ${key}`)
		assert.ok(key in nl, `nl lacks ${key}`)
	}
})

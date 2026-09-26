#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// traffic-paths.spec.mjs — the path explorer's drawing, as geometry.
//
// Usage:
//   node --test tests/traffic-paths.spec.mjs
//
// No browser can load this branch, so the layout is the only visual
// evidence there is: where every node sits, how tall it is, and where
// every band leaves and arrives. The fixtures are endpoint answers built
// by hand, the same shape `TrafficPaths::explore()` returns.

import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
	band,
	chooseNode,
	LAYOUT,
	layoutPaths,
	pathRows,
} from '../src/lib/trafficPaths.js'

/**
 * A node as the endpoint returns it.
 *
 * @param {string} path The page.
 * @param {number} sessions The visits.
 * @param {number} dropOffs The visits that ended there.
 * @param {number} more How many pages a "+N more" node holds, 0 for a page.
 * @return {object} The node.
 */
function node(path, sessions, dropOffs = 0, more = 0) {
	return { path, sessions, dropOffs, more, selected: false }
}

/**
 * Two steps: /home (10, 2 ended) and /about (5, all ended), then /news (6)
 * and "+2 more" (2).
 */
const forward = {
	mode: 'start',
	columns: [
		{
			step: 0,
			sessions: 15,
			dropOffs: 7,
			nodes: [node('/home', 10, 2), node('/about', 5, 5)],
		},
		{
			step: 1,
			sessions: 8,
			dropOffs: 8,
			nodes: [node('/news', 6, 6), node('', 2, 2, 2)],
		},
	],
	links: [
		{ step: 0, source: 0, target: 1, sessions: 2 },
		{ step: 0, source: 0, target: 0, sessions: 6 },
	],
}

describe('layoutPaths', () => {
	it('puts step 0 on the left reading forward, one scale for the whole drawing', () => {
		const drawing = layoutPaths(forward)
		const scale = LAYOUT.maxBandHeight / 15

		assert.equal(drawing.scale, scale)
		assert.deepEqual(
			drawing.columns.map((c) => c.x),
			[0, LAYOUT.columnWidth + LAYOUT.gap],
		)
		assert.equal(drawing.width, 2 * LAYOUT.columnWidth + LAYOUT.gap)

		const [home, about] = drawing.columns[0].nodes
		assert.equal(home.y, LAYOUT.header)
		assert.equal(home.height, 10 * scale)
		assert.equal(about.y, LAYOUT.header + 10 * scale + LAYOUT.nodeGap)
		assert.equal(about.height, 5 * scale)
		assert.equal(home.dropHeight, 2 * scale)
		assert.equal(drawing.height, about.y + about.height)
	})

	it('keeps a small node tall enough for its label, and its band at the true scale', () => {
		const drawing = layoutPaths({
			mode: 'start',
			columns: [
				{
					step: 0,
					sessions: 101,
					dropOffs: 100,
					nodes: [node('/a', 100, 100), node('/b', 1)],
				},
				{ step: 1, sessions: 1, dropOffs: 1, nodes: [node('/c', 1, 1)] },
			],
			links: [{ step: 0, source: 1, target: 0, sessions: 1 }],
		})
		const small = drawing.columns[0].nodes[1]

		assert.equal(small.height, LAYOUT.minNode)
		assert.equal(drawing.links[0].thickness, drawing.scale)
		assert.ok(drawing.scale < LAYOUT.minNode)
	})

	it('stacks the bands leaving one node in target order, from its right edge to the next left edge', () => {
		const drawing = layoutPaths(forward)
		const scale = drawing.scale
		const [toNews, toMore] = drawing.links
		const home = drawing.columns[0].nodes[0]
		const news = drawing.columns[1].nodes[0]
		const more = drawing.columns[1].nodes[1]

		assert.deepEqual(
			drawing.links.map((l) => l.key),
			['0:0>0', '0:0>1'],
		)
		assert.equal(toNews.x0, home.x + home.width)
		assert.equal(toNews.x1, news.x)
		assert.equal(toNews.y0, home.y)
		assert.equal(toNews.y1, news.y)
		assert.equal(toNews.thickness, 6 * scale)
		assert.equal(
			toMore.y0,
			home.y + 6 * scale,
			'the second band starts under the first',
		)
		assert.equal(toMore.y1, more.y)
		assert.equal(toMore.thickness, 2 * scale)
	})

	it('puts the drop-off stub under the bands that left', () => {
		const drawing = layoutPaths(forward)
		const home = drawing.columns[0].nodes[0]
		const about = drawing.columns[0].nodes[1]

		assert.equal(home.dropX, home.x + home.width)
		assert.equal(home.dropY, home.y + 8 * drawing.scale)
		assert.equal(
			about.dropY,
			about.y,
			'nothing left /about, so its stub starts at the top',
		)
	})

	it('mirrors the drawing reading backward: step 0 on the right, bands from left edges', () => {
		const drawing = layoutPaths({ ...forward, mode: 'end' })
		const home = drawing.columns[0].nodes[0]
		const news = drawing.columns[1].nodes[0]

		assert.deepEqual(
			drawing.columns.map((c) => c.x),
			[LAYOUT.columnWidth + LAYOUT.gap, 0],
		)
		assert.equal(drawing.links[0].x0, home.x)
		assert.equal(drawing.links[0].x1, news.x + news.width)
		assert.equal(home.dropX, home.x - LAYOUT.dropWidth)
	})

	it('draws nothing for an empty answer and skips a link to a missing node', () => {
		assert.deepEqual(layoutPaths({ columns: [], links: [] }).width, 0)
		const drawing = layoutPaths({
			...forward,
			links: [{ step: 0, source: 0, target: 9, sessions: 1 }],
		})
		assert.deepEqual(drawing.links, [])
	})
})

describe('band', () => {
	it('is a closed outline from the leaving edge to the arriving edge', () => {
		assert.equal(
			band(0, 10, 100, 40, 5),
			'M 0 10 C 50 10 50 40 100 40 L 100 45 C 50 45 50 15 0 15 Z',
		)
	})
})

describe('pathRows', () => {
	it('lists every node of every step, "+N more" included', () => {
		assert.deepEqual(
			pathRows(forward).map((r) => [
				r.step,
				r.path,
				r.more,
				r.sessions,
				r.dropOffs,
			]),
			[
				[0, '/home', 0, 10, 2],
				[0, '/about', 0, 5, 5],
				[1, '/news', 0, 6, 6],
				[1, '', 2, 2, 2],
			],
		)
	})
})

describe('chooseNode', () => {
	it('chooses a node, keeps earlier choices, drops later ones, and undoes a second choice', () => {
		assert.deepEqual(chooseNode([], 1, '/news'), [null, '/news'])
		assert.deepEqual(chooseNode(['/home', '/news', '/faq'], 1, '/about'), [
			'/home',
			'/about',
		])
		assert.deepEqual(chooseNode(['/home', '/news', '/faq'], 1, '/news'), [
			'/home',
		])
		assert.deepEqual(chooseNode([null, '/news'], 1, '/news'), [])
	})
})

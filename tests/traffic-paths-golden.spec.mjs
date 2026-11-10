#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// traffic-paths-golden.spec.mjs: the path explorer's drawing (columns, node
// boxes, bands, drop-off stubs and size) for forward and backward answers,
// pinned in a golden file before the layout was split into named steps.
//
// Usage:
//   node --test tests/traffic-paths-golden.spec.mjs
//   GOLDEN_RECORD=1 node --test tests/traffic-paths-golden.spec.mjs

import { test } from 'node:test'
import { band, layoutPaths } from '../src/lib/trafficPaths.js'
import { golden } from './support/golden.mjs'

const ANSWER = {
	mode: 'start',
	columns: [
		{ step: 0, sessions: 120, nodes: [{ path: '/', sessions: 100, dropOffs: 20 }, { path: '/a', sessions: 1, dropOffs: 0 }] },
		{ step: 1, sessions: '70', nodes: [{ path: '/b', sessions: 50, dropOffs: 10 }, { path: '(more)', more: 3, sessions: 20, dropOffs: 5 }] },
		{ step: 2, sessions: 30, nodes: [] },
		{ step: 3, sessions: 'x' },
	],
	links: [
		{ step: 1, source: 0, target: 0, sessions: 5 },
		{ step: 0, source: 0, target: 1, sessions: 20 },
		{ step: 0, source: 0, target: 0, sessions: 50 },
		{ step: 0, source: 1, target: 0, sessions: 1 },
		{ step: 0, source: 5, target: 0, sessions: 9 },
		{ step: 3, source: 0, target: 0, sessions: 9 },
	],
}

test('the drawing for forward, backward and empty answers', () => {
	const file = golden('traffic-paths')
	file.check('forward', layoutPaths(ANSWER))
	file.check('backward', layoutPaths({ ...ANSWER, mode: 'end' }))
	file.check('measures', layoutPaths(ANSWER, { columnWidth: 100, gap: 10, minNode: 4, header: 0, maxBandHeight: 100, dropWidth: 2 }))
	file.check('no links', layoutPaths({ columns: ANSWER.columns }))
	file.check('empty', [layoutPaths(null), layoutPaths({ columns: 'x' }), layoutPaths({ columns: [] })])
	file.check('band', [
		band({ x0: 0, y0: 10, x1: 100, y1: 40, thickness: 5 }),
		band({ x0: 1.005, y0: 2.333, x1: 3.999, y1: 4, thickness: 0.125 }),
	])
	file.save()
})

#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// page-blocks-resolve.spec.mjs: how each block type of a contribution page
// resolves (every branch of resolveBlocks' per-block step), pinned in a golden
// file before that step was split into one resolver per block type.
//
// Usage:
//   node --test tests/page-blocks-resolve.spec.mjs
//   GOLDEN_RECORD=1 node --test tests/page-blocks-resolve.spec.mjs

import { test } from 'node:test'
import { resolveBlocks } from '../src/site/pages/collections/pageBlocks.js'
import { golden } from './support/golden.mjs'

const contribution = {
	collections: [
		{ id: 'c1', rowActions: ['upd', 'endp', 'viewDocument', 'prop', 'rm', 'ghost'], itemList: { removeAction: 'rm' } },
		{ id: 'c2', kind: 'timedTask', timedTask: { minutes: 5 } },
		{ id: 'c3', kind: 'timedTask' },
		{ id: 'c4', fieldConfigs: { status: { valueLabels: { a: 'A' } } } },
		null,
	],
	actions: [
		{ id: 'upd', type: 'update' },
		{ id: 'endp', type: 'call', endpoint: '/x', rowField: 'id' },
		{ id: 'viewDocument', type: 'call', endpoint: '/v', rowField: 'id' },
		{ id: 'prop', type: 'propose-change' },
		{ id: 'rm', type: 'call', endpoint: '/rm', rowField: 'id' },
		{ id: 'go', type: 'link' },
		null,
	],
}
const page = {
	blocks: [
		{ type: 'richText', text: 'a' },
		{ type: 'richText', template: 'Hi {{name}}' },
		{ type: 'collection', collection: 'missing' },
		{ type: 'collection', collection: 'c1' },
		{ type: 'detail', collection: 'c1' },
		{ type: 'detail', collection: 'c3' },
		{ type: 'citizenCase', collection: 'c1' },
		{ type: 'collection', collection: 'c2' },
		{ type: 'collection', collection: 'c3' },
		{ type: 'kpi', collection: 'c1' },
		{ type: 'kpi', collection: 'nope' },
		{ type: 'calendar', sources: [{ collection: 'c4', statusField: 'status' }, { collection: 'nope' }, null, { collection: 'c1' }] },
		{ type: 'calendar', sources: [{ collection: 'nope' }] },
		{ type: 'calendar' },
		{ type: 'news' },
		{ type: 'tasks', collection: 'c1' },
		{ type: 'tasks', collection: 'x' },
		{ type: 'inbox' },
		{ type: 'cases', collection: 'c1' },
		{ type: 'steps', collection: 'c1' },
		{ type: 'documents', collection: 'x' },
		{ type: 'timeline', collection: 'c1' },
		{ type: 'greeting', action: 'go' },
		{ type: 'greeting', action: 'gone' },
		{ type: 'greeting' },
		{ type: 'cta', page: 'p1' },
		{ type: 'cta', route: 'r1' },
		{ type: 'action', action: 'go' },
		{ type: 'cta', action: 'go' },
		{ type: 'cta', action: 'gone' },
		{ type: 'action' },
		{ type: 'cta', page: 'p2' },
		{ type: 'unknown' },
		{ type: 'toString' },
		{ type: 'constructor' },
		{},
		null,
	],
}

test('every block type resolves as it did, block by block', () => {
	const file = golden('page-blocks-resolve')
	resolveBlocks(page, contribution).forEach((item, position) => {
		file.check(`${position}:${item.block?.type ?? 'none'}`, item)
	})
	file.check('empty page', resolveBlocks({}, {}))
	file.check('no page', resolveBlocks(null, null))
	file.save()
})

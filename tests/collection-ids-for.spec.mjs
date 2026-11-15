#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// collection-ids-for.spec.mjs: which collections a page reads, once each and
// in reading order, pinned in a golden file before the per-block reading was
// split into named steps.
//
// Usage:
//   node --test tests/collection-ids-for.spec.mjs
//   GOLDEN_RECORD=1 node --test tests/collection-ids-for.spec.mjs

import { test } from 'node:test'
import { collectionIdsFor } from '../src/site/pages/collections/collectionLoader.js'
import { golden } from './support/golden.mjs'

test('the collections a page reads', () => {
	const file = golden('collection-ids-for')
	file.check('pages', [
		null,
		{},
		{ blocks: 'x' },
		{
			record: { collection: 'kinderen' },
			records: { collection: 'kinderen', subtitleLookup: { collection: 'klassen' } },
			blocks: [
				null,
				{ type: 'collection', collection: 'cijfers', lookups: [{ collection: 'vakken' }, null, { collection: 3 }] },
				{ type: 'detail', collection: 'cijfers', status: { collection: 'afwezig' } },
				{ type: 'richText', collection: 'nope', lookups: 'ab' },
				{ type: 'calendar', collection: 'agenda', sources: [{ collection: 'roosters' }, { collection: '' }, null] },
				{ type: 'calendar', sources: 'xy' },
				{ type: 'kpi', collection: 'cijfers' },
				{ type: 'tasks', collection: 'taken' },
				{ type: 'cases', collection: 'zaken' },
				{ type: 'documents', collection: 'stukken', forEachRecord: true },
			],
		},
	].map((page) => collectionIdsFor(page)))
	file.save()
})

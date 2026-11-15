#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// record-lists-from.spec.mjs: which public record lists the page editor
// offers from the contributions answer, pinned before the reading was split
// into named steps.
//
// Usage:
//   node --test tests/record-lists-from.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { recordListsFrom } from '../src/editor/recordLists.js'

test('the lists that name an app and an id, in answer order', () => {
	assert.deepEqual(recordListsFrom(null), [])
	assert.deepEqual(recordListsFrom({ contributions: 'x' }), [])
	assert.deepEqual(
		recordListsFrom({
			contributions: [
				null,
				{ app: 'decidesk', publicRecords: 'x' },
				{
					app: 'decidesk',
					publicRecords: [
						{ id: 'votes', label: 'Stemmingen' },
						{ id: 'papers' },
						{ app: 'other', id: 7, label: '' },
						{ label: 'no id' },
						null,
					],
				},
				{ publicRecords: [{ id: 'orphan' }, { app: 'own', id: 'x', label: 3 }] },
			],
		}),
		[
			{ app: 'decidesk', id: 'votes', label: 'Stemmingen' },
			{ app: 'decidesk', id: 'papers', label: 'papers' },
			{ app: 'other', id: '7', label: '7' },
			{ app: 'own', id: 'x', label: '3' },
		],
	)
})

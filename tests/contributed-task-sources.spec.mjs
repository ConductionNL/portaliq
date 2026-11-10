#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// contributed-task-sources.spec.mjs: which collections "My tasks" reads from
// the contributions, and the entries and routes it makes of their rows,
// pinned in a golden file before both were split into named steps.
//
// Usage:
//   node --test tests/contributed-task-sources.spec.mjs
//   GOLDEN_RECORD=1 node --test tests/contributed-task-sources.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	contributedTaskEntries,
	contributedTaskSources,
} from '../src/site/pages/inbox/contributedTasks.js'
import { golden } from './support/golden.mjs'

const DOSSIQ = {
	app: 'dossiq',
	collections: [
		null,
		{ id: 'vragen', label: 'Vragen', defaultFilters: { state: 'open' } },
		{ id: 'zaken' },
		{ id: 'taken' },
	],
	pages: [
		null,
		{ id: 'leeg' },
		{
			id: 'overzicht',
			blocks: [
				null,
				{ type: 'steps', collection: 'zaken' },
				{ type: 'tasks', collection: 3 },
				{ type: 'tasks', collection: 'ontbreekt' },
				{ type: 'tasks', collection: 'vragen', titleFields: ['summary'], dueField: 'termijn' },
				{ type: 'tasks', collection: 'taken', recordField: 'zaak' },
			],
		},
		{
			id: 'zaak',
			records: { collection: 'zaken' },
			blocks: [
				{ type: 'tasks', collection: 'vragen', recordField: 'zaak' },
				{ type: 'tasks', collection: 'taken', recordField: 'case' },
			],
		},
		{
			id: 'later',
			record: { collection: 'anders' },
			blocks: [{ type: 'tasks', collection: 'vragen', recordField: 'other' }],
		},
	],
}
const NOAPP = {
	collections: [{ id: 'x' }],
	pages: [{ record: { collection: 'y' }, blocks: [{ type: 'tasks', collection: 'x' }] }],
}

const ROWS = [
	{ id: 'v1', summary: 'Stuur de tekening', termijn: '2026-10-29', state: 'open', zaak: 'z-9' },
	{ id: 'v2', summary: 'Beantwoord', state: 'answered', zaak: 'z-9' },
	{ id: 'v3', summary: 'Zonder zaak', state: 'open', termijn: '2026-10-01' },
	{ id: 'v4', summary: 'Zaak als getal', state: 'open', zaak: 9 },
	{ summary: 'Zonder id', state: 'open', zaak: 'z-1' },
]
const NAV_RECORD = [{ key: 'dossiq:zaken', label: 'Uw zaak', contribution: { app: 'dossiq' }, page: DOSSIQ.pages[3] }]
const NAV_OWN = [
	...NAV_RECORD,
	{ key: 'dossiq:vragen', label: 'Vragen', contribution: { app: 'dossiq' }, page: { id: 'vragenlijst', blocks: [{ type: 'collection', collection: 'vragen' }] } },
]

test('the sources: once per app and collection, the first block, the first record', () => {
	const file = golden('contributed-task-sources')
	file.check('object aggregate', contributedTaskSources({ contributions: [DOSSIQ, NOAPP, null] }))
	file.check('list aggregate', contributedTaskSources([NOAPP, DOSSIQ]))
	file.check('no aggregate', contributedTaskSources(null))
	file.check('empty aggregate', contributedTaskSources({}))
	file.save()
	assert.equal(contributedTaskSources([DOSSIQ]).length, 2)
})

test('the entries: open rows, their own page first, else the record they belong to', () => {
	const file = golden('contributed-task-entries')
	const [vragen, taken] = contributedTaskSources([DOSSIQ])
	file.check('no nav', contributedTaskEntries(vragen, ROWS, []))
	file.check('record page', contributedTaskEntries(vragen, ROWS, NAV_RECORD))
	file.check('own page', contributedTaskEntries(vragen, ROWS, NAV_OWN))
	file.check('no record', contributedTaskEntries({ ...vragen, record: null }, ROWS, NAV_RECORD))
	file.check('other source', contributedTaskEntries(taken, [{ id: 't1', title: 'Taak', zaak: 'z-2' }], NAV_RECORD))
	file.check('no rows', contributedTaskEntries(vragen, null, NAV_OWN))
	file.save()
})

#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// case-page-next-step.spec.mjs: the case page tells a resident what is still
// needed, by when, and what to do next (case-page-tasks-decision-dates-and-
// next-step). The banner lists the open tasks of THIS case, a failed read says
// so, both decision dates show when set, the card takes the legal date as its
// due day, and the button on the current step follows the case type.
//
// Usage:
//   node --test tests/case-page-next-step.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	bannerSentence,
	decisionDateRows,
	dueValueOf,
	nextStepView,
	tasksOfCase,
} from '../src/shared/casePage.js'
import { caseCard } from '../src/site/components/mijn/cases.js'
import { t } from './support/page-instance.mjs'
import { loadSfc, renderSfc } from './support/render-sfc.mjs'

const SITE_CASE = 'src/site/components/e/CitizenCase.vue'
const TASKS = {
	id: 'taken',
	register: 'dossiq',
	schema: 'taak',
	caseField: 'zaak',
	titleFields: ['title'],
}
const CASE = {
	id: 'c-82',
	reference: '2026-0082',
	plannedDecisionDate: '2026-10-20',
	legalDecisionDate: '2026-11-01',
}
function WRITABLE (extra = {}) {
  return {
	fields: {},
	writable: [],
	window: { open: false, reason: '' },
	documents: { open: false, reason: '' },
	status: { label: 'In behandeling' },
	...extra,
}
}
function render (props) {
  return renderSfc(SITE_CASE, {
		api: {},
		t,
		locale: 'nl',
		collection: { id: 'cases', register: 'dossiq', schema: 'zaak' },
		row: { id: 'c-82' },
		taskCollections: [TASKS],
		initialData: {
			case: CASE,
			writableSet: WRITABLE(),
			documents: [],
			withdrawal: { declared: false },
		},
		...props,
	})
}
const reads = (rows) => [{ collection: TASKS, rows }]

test('tasksOfCase keeps the open tasks of this case, soonest first, and no other', () => {
	const tasks = tasksOfCase(
		reads([
			{ id: 't2', title: 'Later', zaak: '2026-0082', due: '2026-10-25' },
			{ id: 't1', title: 'Stuur een kopie van uw ID-bewijs', zaak: '2026-0082', due: '2026-10-18' },
			{ id: 't3', title: 'Other case', zaak: '2026-0061', due: '2026-10-01' },
		]),
		['2026-0082', 'c-82'],
	)
	assert.deepEqual(tasks.map((task) => task.id), ['t1', 't2'])
})

test('a collection without caseField, and a closed row, add no task', () => {
	const rows = [{ id: 't1', title: 'x', zaak: '2026-0082', done: true }]
	assert.deepEqual(
		tasksOfCase([{ collection: { id: 'taken' }, rows }], ['2026-0082']),
		[],
	)
	assert.deepEqual(
		tasksOfCase([{ collection: { ...TASKS, closedField: 'done' }, rows }], ['2026-0082']),
		[],
	)
})

test('the banner names the earliest due day and the legal decision date', () => {
	const tasks = tasksOfCase(
		reads([{ id: 't1', title: 'ID', zaak: '2026-0082', due: '2026-10-18' }]),
		['2026-0082'],
	)
	assert.equal(
		bannerSentence(tasks, '2026-11-01', t, 'nl'),
		'We still need documents from you. Send them before 18 oktober, and we will decide by 1 november.',
	)
	assert.equal(
		bannerSentence(tasks, '', t, 'nl'),
		'We still need documents from you. Send them before 18 oktober.',
	)
	assert.equal(bannerSentence([], '2026-11-01', t, 'nl'), '')
})

test('site: one open task shows the banner with a link to the task; another case\'s task shows none', async () => {
	const html = await render({
		initialTaskReads: reads([
			{ id: 't1', title: 'Stuur een kopie van uw ID-bewijs', zaak: '2026-0082', due: '2026-10-18' },
		]),
		nav: [],
	})
	assert.match(html, /data-testid="case-tasks"/)
	assert.match(html, /Send them before 18 oktober, and we will decide by 1 november/)
	assert.match(html, /data-testid="case-task"[^>]*>Stuur een kopie van uw ID-bewijs</)

	const other = await render({
		initialTaskReads: reads([{ id: 't9', title: 'x', zaak: '2026-0061', due: '2026-10-01' }]),
	})
	assert.doesNotMatch(other, /data-testid="case-tasks"/)
	assert.doesNotMatch(other, /data-testid="case-tasks-failed"/)
})

test('site: a failed task read says so and never shows an empty banner', async () => {
	const failed = await render({ initialTaskReads: [], initialTasksFailed: true })
	assert.match(failed, /data-testid="case-tasks-failed"[^>]*>\s*Your tasks could not be loaded\./)
	assert.doesNotMatch(failed, /data-testid="case-tasks"/)

	const quiet = await render({ initialTaskReads: [] })
	assert.doesNotMatch(quiet, /case-tasks-failed/, 'a read that found nothing is not a failure')
})

test('loadTasks marks the list failed when one collection cannot be read', async () => {
	const screen = await loadSfc(SITE_CASE)
	const vm = (answer) => ({
		initialTaskReads: null,
		caseId: 'c-82',
		taskCollections: [TASKS],
		api: { fetchCollection: async () => answer },
		taskReads: [],
		tasksFailed: false,
	})
	const failed = vm(null)
	await screen.methods.loadTasks.call(failed)
	assert.equal(failed.tasksFailed, true)
	const fine = vm([{ id: 't1', zaak: '2026-0082' }])
	await screen.methods.loadTasks.call(fine)
	assert.equal(fine.tasksFailed, false)
	assert.equal(fine.taskReads.length, 1)
})

test('decisionDateRows shows each date only when set', () => {
	assert.deepEqual(
		decisionDateRows(CASE, t, 'nl').map((row) => [row.key, row.value]),
		[
			['plannedDecisionDate', '20 oktober 2026'],
			['legalDecisionDate', '1 november 2026'],
		],
	)
	assert.deepEqual(
		decisionDateRows({ legalDecisionDate: '2026-11-01' }, t, 'nl').map((row) => row.key),
		['legalDecisionDate'],
	)
	assert.deepEqual(decisionDateRows({}, t, 'nl'), [])
})

test('site: Gegevens shows both dates, or only the one that is set', async () => {
	const both = await render({ initialTaskReads: [] })
	assert.match(both, /data-testid="case-date-plannedDecisionDate"/)
	assert.match(both, /data-testid="case-date-legalDecisionDate"/)

	const onlyLegal = await render({
		initialTaskReads: [],
		initialData: {
			case: { id: 'c-82', legalDecisionDate: '2026-11-01' },
			writableSet: WRITABLE(),
			documents: [],
			withdrawal: { declared: false },
		},
	})
	assert.match(onlyLegal, /data-testid="case-date-legalDecisionDate"/)
	assert.doesNotMatch(onlyLegal, /case-date-plannedDecisionDate/)
})

test('the case card takes the legal decision date as its due day', () => {
	assert.equal(dueValueOf({ legalDecisionDate: '2026-11-01', due: '2026-10-01' }, { dueField: 'due' }), '2026-11-01')
	assert.equal(dueValueOf({ due: '2026-10-01' }, { dueField: 'due' }), '2026-10-01')
	const card = caseCard(
		{ id: 'c1', title: 'Aanvraag', legalDecisionDate: '2026-11-01' },
		{},
		{ tr: t, locale: 'nl', today: new Date('2026-10-05') },
	)
	assert.equal(card.dueDay, '1 november')
})

test('the button follows the case type: a task that is open, a page, an action that is offered', () => {
	const status = (action, next) => ({ action, next })
	const open = [{ id: 't1', type: 'aanvullen' }]
	const taskAction = { label: 'Stuur de ontbrekende stukken', kind: 'task', target: 'aanvullen' }

	const view = nextStepView(status(taskAction, { value: 'besluit', label: 'besluit' }), open, () => false)
	assert.equal(view.button.label, 'Stuur de ontbrekende stukken')
	assert.equal(view.button.task.id, 't1')
	assert.equal(view.next, 'besluit')

	assert.equal(nextStepView(status(taskAction), [], () => false).button, null, 'the task is already done')
	assert.equal(nextStepView(status({ label: 'Naar de pagina', kind: 'page', target: '/hulp' }), [], () => false).button.route, '/hulp')
	const act = { label: 'Trek in', kind: 'action', target: 'withdraw' }
	assert.equal(nextStepView(status(act), [], () => false).button, null)
	assert.equal(nextStepView(status(act), [], (id) => id === 'withdraw').button.id, 'withdraw')
	assert.equal(nextStepView(status(null), open, () => true).button, null)
})

test('site: the current step shows its button and the next step in grey', async () => {
	const html = await render({
		initialTaskReads: reads([{ id: 't1', type: 'aanvullen', title: 'ID', zaak: '2026-0082', due: '2026-10-18' }]),
		initialData: {
			case: CASE,
			writableSet: WRITABLE({
				status: {
					label: 'In behandeling',
					action: { label: 'Stuur de ontbrekende stukken', kind: 'task', target: 'aanvullen' },
					next: { value: 'besluit', label: 'besluit' },
				},
			}),
			documents: [],
			withdrawal: { declared: false },
		},
	})
	assert.match(html, /data-testid="case-next-action"[^>]*>\s*Stuur de ontbrekende stukken/)
	assert.match(html, /data-testid="case-next-step"[^>]*>\s*Next step: besluit/)

	const done = await render({
		initialTaskReads: [],
		initialData: {
			case: CASE,
			writableSet: WRITABLE({
				status: {
					label: 'In behandeling',
					action: { label: 'Stuur de ontbrekende stukken', kind: 'task', target: 'aanvullen' },
				},
			}),
			documents: [],
			withdrawal: { declared: false },
		},
	})
	assert.doesNotMatch(done, /case-next-action/)
})

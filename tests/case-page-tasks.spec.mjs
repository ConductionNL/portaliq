#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// case-page-tasks.spec.mjs: which rows tasksOfCase keeps as open tasks of a
// case, how it names, dates and types them and how it orders them, pinned
// branch by branch before the function was split into named steps.
//
// Usage:
//   node --test tests/case-page-tasks.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { bannerSentence, dayWords, nextStepView, tasksOfCase } from '../src/shared/casePage.js'

const COLLECTION = { id: 'taken', caseField: 'zaak', closedField: 'done', label: 'Taak' }

test('no reads, no keys or a collection without a case field give no task', () => {
	const rows = [{ id: 't1', zaak: 'Z1' }]
	assert.deepEqual(tasksOfCase(null, ['Z1']), [])
	assert.deepEqual(tasksOfCase('x', ['Z1']), [])
	assert.deepEqual(tasksOfCase([{ collection: COLLECTION, rows }], null), [])
	assert.deepEqual(tasksOfCase([{ collection: COLLECTION, rows }], ['  ', null, undefined]), [])
	assert.deepEqual(tasksOfCase([{ collection: { caseField: '' }, rows }], ['Z1']), [])
	assert.deepEqual(tasksOfCase([{ collection: { caseField: 3 }, rows }], ['Z1']), [])
	assert.deepEqual(tasksOfCase([null, {}, { collection: COLLECTION, rows: 'no' }], ['Z1']), [])
})

test('a row is kept when its case value matches a trimmed key and it is not closed', () => {
	const rows = [
		{ id: 'a', zaak: ' Z1 ' },
		{ id: 'b', zaak: 'Z2' },
		{ id: 'c', zaak: 'Z1', done: true },
		{ id: 'd', zaak: 'Z1', done: 0 },
		{ id: 'e' },
		{ id: 'f', zaak: null },
		null,
		{ id: 'g', zaak: 7 },
	]
	const tasks = tasksOfCase([{ collection: COLLECTION, rows }], [' Z1', 7])
	assert.deepEqual(tasks.map((task) => task.id), ['a', 'd', 'g'])
	const open = tasksOfCase([{ collection: { caseField: 'zaak' }, rows: [{ id: 'h', zaak: 'Z1', done: true }] }], ['Z1'])
	assert.deepEqual(open.map((task) => task.id), ['h'], 'without a closed field nothing is closed')
})

test('each task carries its id, title, due day, type, collection and row', () => {
	const rows = [
		{ id: 'a', zaak: 'Z1', title: 'Titel', name: 'Naam', due: '2026-10-18', taskType: 'upload', type: 'x' },
		{ '@self': { id: 'b' }, zaak: 'Z1', title: '  ', name: 'Naam', due: 20261018, type: 'sign', kind: 'k' },
		{ zaak: 'Z1', onderwerp: 'Onderwerp', kind: 'k' },
		{ zaak: 'Z1' },
	]
	const tasks = tasksOfCase([{ collection: COLLECTION, rows }], ['Z1'])
	assert.deepEqual(
		tasks.map(({ id, title, due, type }) => ({ id, title, due, type })),
		[
			{ id: 'a', title: 'Titel', due: '2026-10-18', type: 'upload' },
			{ id: 'b', title: 'Naam', due: '', type: 'sign' },
			{ id: '', title: 'Onderwerp', due: '', type: 'k' },
			{ id: '', title: 'Taak', due: '', type: '' },
		],
	)
	assert.equal(tasks[0].collection, COLLECTION)
	assert.equal(tasks[0].row, rows[0])
	const own = tasksOfCase(
		[{ collection: { caseField: 'zaak', titleFields: ['kop'] }, rows: [{ zaak: 'Z1', title: 'T', kop: 'Kop' }, { zaak: 'Z1', title: 'T' }] }],
		['Z1'],
	)
	assert.deepEqual(own.map((task) => task.title), ['Kop', ''])
})

test('tasks come soonest due first, undated and unreadable last, ties in read order', () => {
	const reads = [
		{ collection: COLLECTION, rows: [
			{ id: 'none', zaak: 'Z1' },
			{ id: 'late', zaak: 'Z1', due: '2026-12-01' },
			{ id: 'bad', zaak: 'Z1', due: 'someday' },
		] },
		{ collection: { ...COLLECTION, id: 'other' }, rows: [
			{ id: 'early', zaak: 'Z1', due: '2026-10-01' },
			{ id: 'late2', zaak: 'Z1', due: '2026-12-01' },
		] },
	]
	assert.deepEqual(
		tasksOfCase(reads, ['Z1']).map((task) => task.id),
		['early', 'late', 'late2', 'none', 'bad'],
	)
})

test('a day reads in the page language, with the year when asked, and nothing for no date', () => {
	assert.equal(dayWords('2026-10-18', 'nl'), '18 oktober')
	assert.equal(dayWords('2026-10-18', 'en'), '18 October')
	assert.equal(dayWords('2026-10-18', 'en-US', true), '18 October 2026')
	assert.equal(dayWords('2026-10-18', '', true), '18 oktober 2026')
	assert.equal(dayWords('', 'nl'), '')
	assert.equal(dayWords('someday', 'nl'), '')
})

test('the banner names what it knows and leaves the rest out', () => {
	const echo = (key, vars) => `${key}|${JSON.stringify(vars ?? null)}`
	const tasks = [{ due: '' }, { due: '2026-10-18' }]
	assert.equal(bannerSentence(null, '2026-11-01', echo, 'nl'), '')
	assert.equal(
		bannerSentence(tasks, '2026-11-01', echo, 'nl'),
		'We still need documents from you. Send them before {due}, and we will decide by {legal}.|{"due":"18 oktober","legal":"1 november"}',
	)
	assert.equal(
		bannerSentence(tasks, '', echo, 'nl'),
		'We still need documents from you. Send them before {due}.|{"due":"18 oktober"}',
	)
	assert.equal(bannerSentence([{ due: '' }], '2026-11-01', echo, 'nl'), 'We still need documents from you.|null')
})

test('the step button follows its kind, and the next step its label', () => {
	const task = { type: 'upload' }
	const view = (action, offered = () => true) =>
		nextStepView({ action, next: { label: 'Besluit' } }, [{ type: 'x' }, task], offered)
	assert.deepEqual(view({ label: 'Upload', kind: 'task', target: 'upload' }), {
		button: { label: 'Upload', kind: 'task', task },
		next: 'Besluit',
	})
	assert.equal(view({ label: 'Upload', kind: 'task', target: 'sign' }).button, null)
	assert.deepEqual(view({ label: 'Hulp', kind: 'page', target: '/hulp' }).button, { label: 'Hulp', kind: 'page', route: '/hulp' })
	assert.deepEqual(view({ label: 'Trek in', kind: 'action', target: 'withdraw' }).button, { label: 'Trek in', kind: 'action', id: 'withdraw' })
	assert.equal(view({ label: 'Trek in', kind: 'action', target: 'withdraw' }, () => 'yes').button, null)
	assert.equal(view({ label: 'Iets', kind: 'other', target: 'x' }).button, null)
	assert.equal(view({ label: 3, kind: 'page', target: '/x' }).button, null)
	assert.deepEqual(nextStepView(null, null, () => true), { button: null, next: '' })
	assert.equal(nextStepView({ action: { label: 'U', kind: 'task', target: 'upload' } }, null, () => true).button, null)
})

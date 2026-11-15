#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// row-action-inputs-answers.spec.mjs: the inputs a row declares for an
// action, the words of a forward's answer and the forward a row action sends,
// pinned branch by branch before they were split into named steps.
//
// Usage:
//   node --test tests/row-action-inputs-answers.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { answerWords, rowInputsOf, runRowAction } from '../src/shared/rowAction.js'

test('the inputs a row declares: named, once, with a label, required flag and type', () => {
	const action = { rowInputs: { from: 'vragen' } }
	assert.deepEqual(rowInputsOf(null, {}), [])
	assert.deepEqual(rowInputsOf({ rowInputs: { from: 3 } }, { 3: [{ name: 'a' }] }), [])
	assert.deepEqual(rowInputsOf(action, { vragen: 'x' }), [])
	assert.deepEqual(rowInputsOf(action, null), [])
	assert.deepEqual(
		rowInputsOf(action, {
			vragen: [
				{ name: 'reden', label: 'Reden', required: true, type: 'date' },
				{ name: 'reden', label: 'Dubbel' },
				null,
				{ name: '1x' },
				{ name: 'with space' },
				{ name: 7 },
				{ name: 'toelichting', label: '', required: 'yes', type: 'number' },
				{ name: 'b_2', label: 5 },
			],
		}),
		[
			{ name: 'reden', label: 'Reden', required: true, type: 'date' },
			{ name: 'toelichting', label: 'toelichting', required: false, type: 'text' },
			{ name: 'b_2', label: 'b_2', required: false, type: 'text' },
		],
	)
})

test('the words of an answer: its sentence and its messages per input', () => {
	assert.deepEqual(answerWords(null), { message: '', errors: {} })
	assert.deepEqual(answerWords({ body: 'x' }), { message: '', errors: {} })
	assert.deepEqual(answerWords({ body: null }), { message: '', errors: {} })
	assert.deepEqual(answerWords({ body: { message: ' Gelukt ', errors: ['a'] } }), { message: 'Gelukt', errors: {} })
	assert.deepEqual(answerWords({ body: { message: 3, errors: 'x' } }), { message: '', errors: {} })
	assert.deepEqual(
		answerWords({ body: { errors: { reden: 'Verplicht', leeg: '', getal: 3, ook: 'Fout' } } }),
		{ message: '', errors: { reden: 'Verplicht', ook: 'Fout' } },
	)
})

test('the forward a row action sends, with and without files', async () => {
	const calls = []
	const api = {
		async forwardRowAction(...args) {
			calls.push(args)
			return { ok: true, status: 200, body: {} }
		},
	}
	const collection = { id: 'c' }
	const action = { id: 'act' }
	assert.deepEqual(await runRowAction(null, { collection, row: { id: 'r' }, action }), {
		redirect: null,
		messageKey: 'This is not available right now. Try again later.',
	})
	assert.deepEqual(await runRowAction(api, { collection, row: {}, action }), {
		redirect: null,
		messageKey: 'This is not available right now. Try again later.',
	})
	assert.deepEqual(await runRowAction(api, { collection, row: null, action }), {
		redirect: null,
		messageKey: 'This is not available right now. Try again later.',
	})
	const done = await runRowAction(api, { collection, row: { '@self': { id: 'r2' } }, action })
	assert.equal(done.messageKey, 'Done.')
	await runRowAction(api, { collection, row: { id: 'r' }, action, answers: { a: 1 } })
	const file = { name: 'f.pdf' }
	await runRowAction(api, { collection, row: { id: 'r' }, action: { id: 'up', files: { field: 'bijlage' } }, files: [file] })
	await runRowAction(api, { collection, row: { id: 'r' }, action: { id: 'up', files: { field: 'bijlage' } }, files: [] })
	await runRowAction(api, { collection, row: { id: 'r' }, action: { id: 'up', files: {} }, files: [file] })
	assert.deepEqual(calls, [
		[collection, 'r2', 'act', {}],
		[collection, 'r', 'act', { a: 1 }],
		[collection, 'r', 'up', {}, '', { field: 'bijlage', files: [file] }],
		[collection, 'r', 'up', {}],
		[collection, 'r', 'up', {}],
	])
})

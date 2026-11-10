#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// timed-task-attempt-calls.spec.mjs: what starting and handing in an attempt
// send to the app and answer back (with and without an access code, a
// refused or empty start, an attempt already handed in), pinned before both
// took the task's actions as one object.
//
// Usage:
//   node --test tests/timed-task-attempt-calls.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { startAttempt, submitAttempt } from '../src/shared/timedTask.js'

const BLOCK = { start: 'startTest', submit: 'submitTest' }

/**
 * A portal api that answers each call in turn and records it.
 *
 * @param {Array<object>} answers The answers, in order.
 * @return {{api: object, calls: Array}}
 */
function answering(answers) {
	const calls = []
	return {
		calls,
		api: {
			async forwardAction(app, actionId, body) {
				calls.push([app, actionId, body])
				return answers[calls.length - 1]
			},
		},
	}
}

test('a start sends the task and, only when given, its access code', async () => {
	const { api, calls } = answering([
		{ ok: false, status: 403, body: { error: 'access_code_wrong', message: 'Nee' } },
		{ ok: true, status: 200, body: {} },
		{ ok: false, status: 500, body: null },
	])
	assert.deepEqual(await startAttempt(api, 'learniq', BLOCK, 't1', 'CODE'), { ok: false, error: 'access_code_wrong', message: 'Nee' })
	assert.deepEqual(await startAttempt(api, 'learniq', BLOCK, 't2'), { ok: false, error: '', message: '' })
	assert.deepEqual(await startAttempt(api, 'learniq', BLOCK, 't3', ''), { ok: false, error: '', message: '' })
	assert.deepEqual(calls, [
		['learniq', 'startTest', { taskId: 't1', accessCode: 'CODE' }],
		['learniq', 'startTest', { taskId: 't2' }],
		['learniq', 'startTest', { taskId: 't3' }],
	])
})

test('a hand-in flushes first; one already handed in counts as handed in', async () => {
	const order = []
	const queue = (saved) => ({
		flush: async () => {
			order.push('flush')
			return saved
		},
	})
	const { api, calls } = answering([
		{ ok: true, status: 200 },
		{ ok: false, status: 409 },
		{ ok: false, status: 500 },
	])
	const record = api.forwardAction
	api.forwardAction = async (...args) => {
		order.push('submit')
		return record(...args)
	}
	assert.deepEqual(await submitAttempt(api, 'learniq', BLOCK, 'a1', queue(true)), { ok: true, allSaved: true })
	assert.deepEqual(await submitAttempt(api, 'learniq', BLOCK, 'a1', queue(false)), { ok: true, allSaved: false })
	assert.deepEqual(await submitAttempt(api, 'learniq', BLOCK, 'a1', queue(true)), { ok: false, allSaved: true })
	assert.deepEqual(calls, [
		['learniq', 'submitTest', { attemptId: 'a1' }],
		['learniq', 'submitTest', { attemptId: 'a1' }],
		['learniq', 'submitTest', { attemptId: 'a1' }],
	])
	assert.deepEqual(order, ['flush', 'submit', 'flush', 'submit', 'flush', 'submit'])
})

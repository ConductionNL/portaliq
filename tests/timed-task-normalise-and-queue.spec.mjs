#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// timed-task-normalise-and-queue.spec.mjs: every branch of the attempt
// sanitiser and of the save queue's send loop in src/shared/timedTask.js,
// pinned before the two were split into named steps.
//
// Usage:
//   node --test tests/timed-task-normalise-and-queue.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createSaveQueue, normaliseAttempt } from '../src/shared/timedTask.js'

test('an attempt without a usable id is no attempt', () => {
	assert.equal(normaliseAttempt(null), null)
	assert.equal(normaliseAttempt(undefined), null)
	assert.equal(normaliseAttempt({}), null)
	assert.equal(normaliseAttempt({ attemptId: 7 }), null)
	assert.equal(normaliseAttempt({ attemptId: '' }), null)
})

test('a bare attempt gets empty defaults of the right shape', () => {
	assert.deepEqual(normaliseAttempt({ attemptId: 'a', items: 'junk', responses: 'x' }), {
		attemptId: 'a',
		title: '',
		serverNow: null,
		deadlineAt: null,
		items: [],
		responses: {},
	})
	assert.deepEqual(normaliseAttempt({ attemptId: 'a', responses: null }).responses, {})
})

test('a full attempt keeps its typed fields and copies its responses', () => {
	const responses = { q1: 'A' }
	const attempt = normaliseAttempt({
		attemptId: 'a',
		title: 'Toets',
		serverNow: '2026-01-01T10:00:00Z',
		deadlineAt: '2026-01-01T11:00:00Z',
		responses,
		items: [
			{
				itemId: 'q1',
				type: 'match',
				prompt: 'Koppel',
				points: 0,
				choices: [{ id: 'c', label: 7 }],
				sources: [{ id: 's', label: 'S' }],
				targets: [{ id: 't', label: 'T' }],
			},
		],
	})
	assert.deepEqual(attempt, {
		attemptId: 'a',
		title: 'Toets',
		serverNow: '2026-01-01T10:00:00Z',
		deadlineAt: '2026-01-01T11:00:00Z',
		items: [
			{
				itemId: 'q1',
				type: 'match',
				prompt: 'Koppel',
				points: 0,
				choices: [{ id: 'c', label: 'c' }],
				sources: [{ id: 's', label: 'S' }],
				targets: [{ id: 't', label: 'T' }],
			},
		],
		responses: { q1: 'A' },
	})
	assert.notEqual(attempt.responses, responses, 'the responses are a copy')
})

test('items without an id are dropped and loose fields fall back', () => {
	const attempt = normaliseAttempt({
		attemptId: 'a',
		title: 3,
		serverNow: 1,
		deadlineAt: {},
		items: [null, { itemId: '' }, { itemId: 5 }, { itemId: 'q', type: 2, prompt: [], points: '3', choices: 'x' }],
	})
	assert.equal(attempt.title, '')
	assert.equal(attempt.serverNow, null)
	assert.equal(attempt.deadlineAt, null)
	assert.deepEqual(attempt.items, [
		{
			itemId: 'q',
			type: 'text',
			prompt: '',
			points: null,
			choices: [],
			sources: [],
			targets: [],
		},
	])
})

test('the queue marks a refused, a thrown and an accepted save apart', async () => {
	const answers = {
		ok: () => Promise.resolve({ ok: true }),
		refused: () => Promise.resolve({ ok: false, status: 500 }),
		thrown: () => Promise.reject(new Error('offline')),
		syncThrow: () => {
			throw new Error('boom')
		},
		nothing: () => Promise.resolve(undefined),
	}
	const states = []
	const queue = createSaveQueue((itemId) => answers[itemId](), (s) => states.push(s))
	queue.set('ok', 1)
	queue.set('refused', 2)
	queue.set('thrown', 3)
	queue.set('syncThrow', 4)
	await queue.set('nothing', 5)
	assert.deepEqual(queue.state(), {
		unsaved: ['refused', 'thrown', 'syncThrow', 'nothing'],
		failed: ['refused', 'thrown', 'syncThrow', 'nothing'],
		closed: false,
	})
	assert.equal(states.length, 10, 'one state per set and one per send')
})

test('a 409 closes the queue even when the save says ok', async () => {
	const sent = []
	const queue = createSaveQueue((itemId) => {
		sent.push(itemId)
		return Promise.resolve({ ok: true, status: 409 })
	})
	queue.set('q1', 'A')
	await queue.set('q2', 'B')
	assert.deepEqual(sent, ['q1'])
	assert.deepEqual(queue.state(), { unsaved: ['q2'], failed: [], closed: true })
	assert.equal(await queue.flush(), false)
})

test('an answer changed while its save is in flight stays unsaved', async () => {
	let release
	const sent = []
	const queue = createSaveQueue((itemId, value) => {
		sent.push([itemId, value])
		if (sent.length === 1) {
			return new Promise((resolve) => {
				release = () => resolve({ ok: false })
			})
		}
		return Promise.resolve({ ok: true })
	})
	queue.set('q1', 'A')
	queue.set('q1', 'B')
	release()
	await queue.flush()
	assert.deepEqual(sent, [['q1', 'A'], ['q1', 'B']])
	assert.deepEqual(queue.state(), { unsaved: [], failed: [], closed: false })
})

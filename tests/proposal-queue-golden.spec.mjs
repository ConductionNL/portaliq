#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// proposal-queue-golden.spec.mjs: what the proposal queue loads and how it
// accepts, accepts despite drift and rejects a proposal: allowed, drifted,
// forbidden and refused, with the exact routes and bodies, pinned before the
// queue was split into named steps.
//
// Usage:
//   node --test tests/proposal-queue-golden.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createProposalQueue } from '../src/integrations/proposalQueue.js'

/**
 * The queue over a transport that answers once, or rejects.
 *
 * @param {object} answer `{data}` or `{reject}`.
 * @return {{queue: object, calls: Array<object>}} The queue and its calls.
 */
function queueWith(answer) {
	const calls = []
	const reply = (verb) => async (address, second) => {
		calls.push({ verb, address, second })
		if (answer.reject) {
			throw answer.reject
		}
		return { data: answer.data }
	}
	const queue = createProposalQueue({
		get: reply('get'),
		post: reply('post'),
		url: (path, params) => path.replace('{id}', params.id ?? ''),
	})
	return { queue, calls }
}

const OBJECT = { register: 'r', schema: 's', objectId: 'o1' }

test('loading: nothing to ask, ready, empty, forbidden and failed', async () => {
	for (const missing of [{}, { register: 'r', schema: 's' }, { register: 'r', objectId: 'o' }]) {
		const { queue, calls } = queueWith({ data: {} })
		assert.deepEqual(await queue.load(missing), { state: 'empty', proposals: [] })
		assert.equal(calls.length, 0)
	}
	const ready = queueWith({ data: { proposals: [{ id: 'p' }] } })
	assert.deepEqual(await ready.queue.load(OBJECT), { state: 'ready', proposals: [{ id: 'p' }] })
	assert.deepEqual(ready.calls, [{ verb: 'get', address: '/api/proposals', second: { params: { register: 'r', schema: 's', id: 'o1' } } }])
	assert.deepEqual(await queueWith({ data: { proposals: 'x' } }).queue.load(OBJECT), { state: 'empty', proposals: [] })
	assert.deepEqual(await queueWith({ data: null }).queue.load(OBJECT), { state: 'empty', proposals: [] })
	assert.deepEqual(await queueWith({ reject: { response: { status: 403 } } }).queue.load(OBJECT), { state: 'forbidden', proposals: [] })
	assert.deepEqual(await queueWith({ reject: new Error('down') }).queue.load(OBJECT), { state: 'error', proposals: [] })
})

test('deciding: accepted, accepted despite drift, rejected with a reason, none without', async () => {
	const ok = queueWith({ data: {} })
	assert.deepEqual(await ok.queue.accept({ uuid: 'u1', id: 'i1' }), { outcome: 'accepted' })
	assert.deepEqual(await ok.queue.acceptAnyway({ id: 'i2' }), { outcome: 'accepted' })
	assert.deepEqual(await ok.queue.reject({ id: 'i3' }, '  past  '), { outcome: 'rejected' })
	assert.deepEqual(await ok.queue.reject({ id: 'i4' }, '  '), { outcome: 'reasonRequired' })
	assert.deepEqual(await ok.queue.accept(null), { outcome: 'accepted' })
	assert.deepEqual(ok.calls, [
		{ verb: 'post', address: '/api/proposals/u1/accept', second: {} },
		{ verb: 'post', address: '/api/proposals/i2/accept-confirming-drift', second: {} },
		{ verb: 'post', address: '/api/proposals/i3/reject', second: { reason: 'past' } },
		{ verb: 'post', address: '/api/proposals//accept', second: {} },
	])
})

test('a decision refused: drifted with and without the drift, forbidden, failed', async () => {
	assert.deepEqual(await queueWith({ reject: { response: { status: 409, data: { error: 'drifted', drift: [{ field: 'a' }] } } } }).queue.accept({ id: 'p' }), { outcome: 'drifted', drift: [{ field: 'a' }] })
	assert.deepEqual(await queueWith({ reject: { response: { status: 409, data: { error: 'drifted', drift: 'x' } } } }).queue.accept({ id: 'p' }), { outcome: 'drifted', drift: [] })
	assert.deepEqual(await queueWith({ reject: { response: { status: 409, data: { error: 'locked' } } } }).queue.accept({ id: 'p' }), { outcome: 'refused', error: 'locked' })
	assert.deepEqual(await queueWith({ reject: { response: { status: 403, data: { error: 'drifted' } } } }).queue.reject({ id: 'p' }, 'x'), { outcome: 'refused', error: 'drifted' })
	assert.deepEqual(await queueWith({ reject: new Error('down') }).queue.acceptAnyway({ id: 'p' }), { outcome: 'refused', error: '' })
})

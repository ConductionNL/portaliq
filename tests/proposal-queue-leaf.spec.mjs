#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// proposal-queue-leaf.spec.mjs: the review surface of the change-proposal
// queue (change-proposal-queue T06). A reviewer on another app's record sees
// the queued proposals with their values, accepts one, is asked again when the
// record moved since, and rejects only with a reason. The file also pins the
// wiring that makes the leaf visible at all: the JS half carries the same id,
// surfaces and render mode as the PHP half, the `leaves` bundle registers it,
// webpack builds that bundle, and portaliq's own bundle registers it too.
//
// Usage:
//   node --test tests/proposal-queue-leaf.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	createProposalQueue,
	formatValue,
} from '../src/integrations/proposalQueue.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const read = (path) => readFileSync(join(ROOT, path), 'utf8')

const PROPOSAL = {
	uuid: 'p-1',
	proposedBy: 'colleague-bob',
	channel: 'staff',
	note: 'New number',
	changes: [
		{
			property: 'applicantPhone',
			currentValue: '0612345678',
			proposedValue: '0687654321',
		},
	],
}

/**
 * The queue over a recording transport.
 *
 * @param {Function} answer (method, url, body) => { status, data }
 * @return {{queue: object, calls: Array}}
 */
function build(answer) {
	const calls = []
	const request = async (method, url, body) => {
		calls.push({ method, url, body })
		const reply = answer(method, url, body)
		if (reply.status >= 400) {
			const error = new Error('HTTP ' + reply.status)
			error.response = reply
			throw error
		}
		return reply
	}
	const queue = createProposalQueue({
		get: (url, config) => request('GET', url, config),
		post: (url, body) => request('POST', url, body),
		url: (path, params) =>
			'/apps/portaliq' + path.replace(/\{(\w+)\}/g, (m, key) => params[key]),
	})
	return { queue, calls }
}

test('the queue is read for the host record', async () => {
	const { queue, calls } = build(() => ({
		status: 200,
		data: { proposals: [PROPOSAL] },
	}))
	const result = await queue.load({
		register: 'dossiq',
		schema: 'zaak',
		objectId: 'zaak-1',
	})

	assert.equal(result.state, 'ready')
	assert.deepEqual(result.proposals, [PROPOSAL])
	assert.equal(calls[0].url, '/apps/portaliq/api/proposals')
	assert.deepEqual(calls[0].body, {
		params: { register: 'dossiq', schema: 'zaak', id: 'zaak-1' },
	})
})

test('someone who may not review sees that, not an empty queue', async () => {
	const { queue } = build(() => ({ status: 403, data: { error: 'forbidden' } }))
	const result = await queue.load({
		register: 'dossiq',
		schema: 'zaak',
		objectId: 'zaak-1',
	})

	assert.equal(result.state, 'forbidden')
	assert.deepEqual(result.proposals, [])
})

test('a host without a record reads nothing', async () => {
	const { queue, calls } = build(() => ({ status: 200, data: { proposals: [] } }))
	const result = await queue.load({
		register: 'dossiq',
		schema: 'zaak',
		objectId: '',
	})

	assert.equal(result.state, 'empty')
	assert.equal(calls.length, 0)
})

test('accepting posts to the accept route', async () => {
	const { queue, calls } = build(() => ({ status: 200, data: { accepted: true } }))
	const result = await queue.accept(PROPOSAL)

	assert.equal(result.outcome, 'accepted')
	assert.equal(calls[0].method, 'POST')
	assert.equal(calls[0].url, '/apps/portaliq/api/proposals/p-1/accept')
})

test('a record that moved since is shown with both values and needs a second decision', async () => {
	const drift = [
		{
			property: 'applicantPhone',
			snapshot: '0612345678',
			current: '0611111111',
			proposedValue: '0687654321',
		},
	]
	const { queue, calls } = build((method, url) =>
		url.endsWith('/accept')
			? { status: 409, data: { error: 'drifted', drift } }
			: { status: 200, data: { accepted: true } },
	)

	const first = await queue.accept(PROPOSAL)
	assert.equal(first.outcome, 'drifted')
	assert.deepEqual(first.drift, drift)

	const second = await queue.acceptAnyway(PROPOSAL)
	assert.equal(second.outcome, 'accepted')
	assert.equal(
		calls[1].url,
		'/apps/portaliq/api/proposals/p-1/accept-confirming-drift',
	)
})

test('a reject without a reason is not sent', async () => {
	const { queue, calls } = build(() => ({ status: 200, data: { rejected: true } }))

	assert.equal((await queue.reject(PROPOSAL, '   ')).outcome, 'reasonRequired')
	assert.equal(calls.length, 0)

	const result = await queue.reject(PROPOSAL, 'Number belongs to someone else')
	assert.equal(result.outcome, 'rejected')
	assert.deepEqual(calls[0].body, { reason: 'Number belongs to someone else' })
})

test('a refused decision says it was refused', async () => {
	const { queue } = build(() => ({ status: 403, data: { error: 'forbidden' } }))

	assert.equal((await queue.accept(PROPOSAL)).outcome, 'refused')
	assert.equal((await queue.reject(PROPOSAL, 'No')).outcome, 'refused')
})

test('values read as text, an empty one as a dash', () => {
	assert.equal(formatValue('0612345678'), '0612345678')
	assert.equal(formatValue(null), '–')
	assert.equal(formatValue(''), '–')
	assert.equal(formatValue({ street: 'Dorpsstraat' }), '{"street":"Dorpsstraat"}')
})

test('both halves carry the same id, surfaces and render mode', () => {
	const php = read('lib/Listener/RegisterProposalLeavesListener.php')
	const js = read('src/integrations/registerProposalQueueLeaf.js')

	assert.match(php, /QUEUE_LEAF_ID = 'portaliq-change-proposal-queue'/)
	assert.match(
		js,
		/PROPOSAL_QUEUE_INTEGRATION_ID = 'portaliq-change-proposal-queue'/,
	)
	assert.match(php, /'detail-page',\s*'single-entity',/)
	assert.match(js, /SURFACES = \['detail-page', 'single-entity'\]/)
	assert.match(php, /RENDER_MODE_MOUNT/)
	assert.match(js, /renderMode: 'mount'/)
})

test("the leaf reaches other apps' pages and portaliq's own", () => {
	const code = (text) =>
		text
			.split('\n')
			.filter(
				(line) =>
					!line.trim().startsWith('//') && !line.trim().startsWith('*'),
			)
			.join('\n')

	assert.match(code(read('src/leaves.js')), /registerProposalQueueLeaf\(\)/)
	assert.match(
		code(read('webpack.config.js')),
		/leaves:\s*\{[^}]*src', 'leaves\.js'\)[^}]*appId \+ '-leaves\.js'/s,
	)
	assert.match(code(read('src/main.js')), /registerProposalQueueLeaf\(\)/)
})

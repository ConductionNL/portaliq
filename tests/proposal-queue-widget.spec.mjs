#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// proposal-queue-widget.spec.mjs: the change-proposal queue widget for both
// of its readers (change-proposals-reach-the-case-page T04). A reviewer gets
// the queue; a colleague who may read but not review gets "Wijziging
// voorstellen" with the declared properties, sends only what changed, lists
// their own proposals on the record and withdraws a queued one; a user who may
// not read the record gets nothing.
//
// Usage:
//   node --test tests/proposal-queue-widget.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	changedFields,
	createProposalQueue,
	inputValue,
} from '../src/integrations/proposalQueue.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const read = (path) => readFileSync(join(ROOT, path), 'utf8')

const HOST = { register: 'dossiq', schema: 'zaak', objectId: 'zaak-1' }

const FIELDS = [
	{ property: 'applicantPhone', value: '0612345678' },
	{ property: 'applicantEmail', value: null },
	{ property: 'houseNumber', value: 12 },
]

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
		url: (path, params) => path.replace('{id}', params.id || '{id}'),
	})
	return { queue, calls }
}

test('a reviewer gets the queue view', async () => {
	const { queue, calls } = build(() => ({ status: 200, data: { mayReview: true, proposable: [] } }))

	assert.deepEqual(await queue.loadContext(HOST), { view: 'review', fields: [] })
	assert.deepEqual(calls, [{
		method: 'GET',
		url: '/api/proposals/context',
		body: { params: { register: 'dossiq', schema: 'zaak', id: 'zaak-1' } },
	}])
})

test('a reader gets the propose view with the declared fields', async () => {
	const { queue } = build(() => ({
		status: 200,
		data: { mayReview: false, proposable: [...FIELDS, { property: '' }, { value: 'x' }] },
	}))

	assert.deepEqual(await queue.loadContext(HOST), { view: 'propose', fields: FIELDS })
})

test('a user who may not read the record sees nothing, a failure is an error', async () => {
	for (const status of [401, 403]) {
		const { queue } = build(() => ({ status, data: { error: 'forbidden' } }))
		assert.deepEqual(await queue.loadContext(HOST), { view: 'hidden', fields: [] })
	}
	const { queue } = build(() => ({ status: 500, data: {} }))
	assert.deepEqual(await queue.loadContext(HOST), { view: 'error', fields: [] })

	const { queue: unhosted, calls } = build(() => ({ status: 200, data: {} }))
	assert.deepEqual(await unhosted.loadContext({ register: 'dossiq', schema: '', objectId: 'zaak-1' }), { view: 'hidden', fields: [] })
	assert.equal(calls.length, 0)
})

test('the form is prefilled with the values now', () => {
	assert.equal(inputValue('0612345678'), '0612345678')
	assert.equal(inputValue(null), '')
	assert.equal(inputValue(undefined), '')
	assert.equal(inputValue(12), '12')
	assert.equal(inputValue({ street: 'Dorpsstraat' }), '{"street":"Dorpsstraat"}')
})

test('only the fields whose value changed are proposed', () => {
	const values = { applicantPhone: '0687654321', applicantEmail: '', houseNumber: '12' }
	assert.deepEqual(changedFields(FIELDS, values), [
		{ property: 'applicantPhone', proposedValue: '0687654321' },
	])
	assert.deepEqual(changedFields(FIELDS, { applicantEmail: 'a@b.nl', status: 'closed' }), [
		{ property: 'applicantEmail', proposedValue: 'a@b.nl' },
	])
	assert.deepEqual(changedFields(FIELDS, {}), [])
})

test('the proposal body carries only the changed fields and the note', async () => {
	const { queue, calls } = build(() => ({ status: 200, data: { proposal: { uuid: 'p-1', state: 'queued' } } }))
	const values = { applicantPhone: '0687654321', applicantEmail: '', houseNumber: '12' }

	const result = await queue.propose(HOST, FIELDS, values, '  New number  ')

	assert.deepEqual(result, { outcome: 'proposed', proposal: { uuid: 'p-1', state: 'queued' } })
	assert.deepEqual(calls, [{
		method: 'POST',
		url: '/api/proposals',
		body: {
			register: 'dossiq',
			schema: 'zaak',
			id: 'zaak-1',
			changes: [{ property: 'applicantPhone', proposedValue: '0687654321' }],
			note: 'New number',
		},
	}])
	assert.equal('proposable' in calls[0].body, false, 'the client never sends its own allow-list')
})

test('nothing is sent when nothing changed, and a refusal says why', async () => {
	const { queue, calls } = build(() => ({ status: 422, data: { error: 'property_not_proposable' } }))

	assert.deepEqual(await queue.propose(HOST, FIELDS, { applicantPhone: '0612345678' }, 'x'), { outcome: 'nothingChanged' })
	assert.equal(calls.length, 0)
	assert.deepEqual(
		await queue.propose(HOST, FIELDS, { applicantPhone: '1' }, ''),
		{ outcome: 'refused', error: 'property_not_proposable' },
	)
})

test('the colleague lists their own proposals on the record', async () => {
	const own = [{ uuid: 'p-1', state: 'queued' }, { uuid: 'p-0', state: 'withdrawn' }]
	const { queue, calls } = build(() => ({ status: 200, data: { proposals: own } }))

	assert.deepEqual(await queue.loadMine(HOST), { state: 'ready', proposals: own })
	assert.deepEqual(calls[0], {
		method: 'GET',
		url: '/api/proposals/mine',
		body: { params: { register: 'dossiq', schema: 'zaak', id: 'zaak-1' } },
	})

	const { queue: failing } = build(() => ({ status: 403, data: {} }))
	assert.deepEqual(await failing.loadMine(HOST), { state: 'error', proposals: [] })
})

test('the colleague withdraws a queued proposal on the staff route', async () => {
	const { queue, calls } = build(() => ({ status: 200, data: { withdrawn: true } }))

	assert.deepEqual(await queue.withdraw({ uuid: 'p-1' }), { outcome: 'withdrawn' })
	assert.deepEqual(calls, [{ method: 'POST', url: '/api/proposals/p-1/withdraw', body: {} }])

	const { queue: refused } = build(() => ({ status: 403, data: { error: 'not_queued' } }))
	assert.deepEqual(await refused.withdraw({ uuid: 'p-1' }), { outcome: 'refused', error: 'not_queued' })
})

test('the widget asks for the view first and draws both', () => {
	const widget = read('src/integrations/ProposalQueueWidget.vue')
	assert.match(widget, /this\.queue\.loadContext\(this\.host\(\)\)/)
	assert.match(widget, /<template v-if="view === 'review'">/)
	assert.match(widget, /<template v-else-if="view === 'propose'">/)
	assert.match(widget, /t\('portaliq', 'Propose a change'\)/)
	assert.match(widget, /v-if="proposal\.state === 'queued'"/)
})

test('the colleague routes are declared', () => {
	const routes = read('appinfo/routes.php')
	assert.match(routes, /'proposal#context', 'url' => '\/api\/proposals\/context', 'verb' => 'GET'/)
	assert.match(routes, /'proposal#mineOnRecord', 'url' => '\/api\/proposals\/mine', 'verb' => 'GET'/)
	assert.match(routes, /'proposal#withdrawAsColleague', 'url' => '\/api\/proposals\/\{id\}\/withdraw', 'verb' => 'POST'/)
})

test('the form title is Dutch in the Dutch catalogue', () => {
	const nl = JSON.parse(read('l10n/nl.json')).translations
	assert.equal(nl['Propose a change'], 'Wijziging voorstellen')
})

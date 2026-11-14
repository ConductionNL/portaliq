#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// portal-api-reads-and-forms.spec.mjs: what the shared portal API
// (src/shared/portalApi.js) answers for the resident's cases, their message
// threads and a collection read, how it completes a task, and how a row
// action's answers become form fields; pinned on every branch before these
// were split into named steps.
//
// Usage:
//   node --test tests/portal-api-reads-and-forms.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createPortalApi, rowActionFormData } from '../src/shared/portalApi.js'

const BASE = '/apps/portaliq/portal/api'

/**
 * A fetch that answers every call from `respond`, and the calls it saw.
 *
 * @param {(url: string, init: object) => object|Error} respond The answer, a response shape or an Error to throw.
 * @return {Array<object>} The calls.
 */
function answering(respond) {
	const calls = []
	globalThis.fetch = async (url, init = {}) => {
		calls.push({ url, init })
		const answer = respond(url, init)
		if (answer instanceof Error) {
			throw answer
		}
		return answer
	}
	return calls
}

/**
 * A response shape.
 *
 * @param {number} status The status.
 * @param {unknown} body The JSON body, or an Error for a body that is not JSON.
 * @return {object} The response.
 */
function response(status, body) {
	return {
		ok: status >= 200 && status < 300,
		status,
		json: async () => {
			if (body instanceof Error) {
				throw body
			}
			return body
		},
	}
}

const api = () => createPortalApi({ apiBase: BASE }, { getToken: () => 'tok', setToken() {} })

test('my cases: the cases only on an ok answer, the mandates always, the refusal named', async () => {
	answering(() => response(200, { cases: [{ id: 1 }], mandates: [{ id: 'm' }], activeMandate: { id: 'm' } }))
	assert.deepEqual(await api().fetchMyCases(), {
		ok: true, status: 200, cases: [{ id: 1 }], mandates: [{ id: 'm' }], activeMandate: { id: 'm' }, error: '',
	})
	answering(() => response(200, { cases: 'x', mandates: null }))
	assert.deepEqual(await api().fetchMyCases(), {
		ok: true, status: 200, cases: [], mandates: [], activeMandate: null, error: '',
	})
	answering(() => response(403, { cases: [{ id: 1 }], mandates: [{ id: 'm' }], error: 'mandate_required' }))
	assert.deepEqual(await api().fetchMyCases(), {
		ok: false, status: 403, cases: [], mandates: [{ id: 'm' }], activeMandate: null, error: 'mandate_required',
	})
	answering(() => response(500, new Error('not json')))
	assert.deepEqual(await api().fetchMyCases(), {
		ok: false, status: 500, cases: [], mandates: [], activeMandate: null, error: '',
	})
	answering(() => new Error('offline'))
	assert.deepEqual(await api().fetchMyCases(), {
		ok: false, status: 0, cases: [], mandates: [], activeMandate: null, error: '',
	})
	answering(() => response(500, { error: 7 }))
	assert.equal((await api().fetchMyCases()).error, '7')
	answering(() => response(200, null))
	assert.deepEqual((await api().fetchMyCases()).cases, [])
})

test('the threads: the list, or none (null with orNull) on any failure', async () => {
	const calls = answering(() => response(200, [{ id: 't' }]))
	assert.deepEqual(await api().fetchThreads(), [{ id: 't' }])
	assert.match(calls[0].url, /\/api\/messages\/threads$/)
	assert.equal(calls[0].init.headers.Authorization, 'Bearer tok')
	assert.deepEqual(await api().fetchThreads({ orNull: true }), [{ id: 't' }])
	for (const answer of [response(200, { threads: [] }), response(403, []), response(200, new Error('x')), new Error('offline')]) {
		answering(() => answer)
		assert.deepEqual(await api().fetchThreads(), [])
		assert.equal(await api().fetchThreads({ orNull: true }), null)
	}
})

test('a collection read: its objects, else none, or null with orNull, and a throw without it', async () => {
	const collection = { id: 'mijnZaken', register: 'r', schema: 's' }
	const calls = answering(() => response(200, { objects: [{ id: 1 }] }))
	assert.deepEqual(await api().fetchCollection(collection), [{ id: 1 }])
	assert.match(calls[0].url, /\?collection=mijnZaken$/)
	for (const body of [{ objects: 'x' }, {}, null]) {
		answering(() => response(200, body))
		assert.deepEqual(await api().fetchCollection(collection), [])
		assert.equal(await api().fetchCollection(collection, { orNull: true }), null)
	}
	answering(() => response(403, {}))
	assert.deepEqual(await api().fetchCollection(collection), [], 'a refusal reads as none')
	answering(() => new Error('offline'))
	await assert.rejects(api().fetchCollection(collection), { name: 'TypeError', message: 'The collection could not be read.' })
	assert.equal(await api().fetchCollection(collection, { orNull: true }), null)
	answering(() => response(200, new Error('not json')))
	await assert.rejects(api().fetchCollection(collection), TypeError)
})

test('a task completes as multipart, with what the server says', async () => {
	const file = new File(['x'], 'bewijs.pdf')
	const calls = answering(() => response(200, { code: 'done', next: 1 }))
	assert.deepEqual(await api().completeTask('a/b', { comment: 'Klaar', files: [file, file] }), {
		ok: true, status: 200, code: 'done', body: { code: 'done', next: 1 },
	})
	assert.equal(calls[0].url, BASE + '/tasks/a%2Fb/complete')
	assert.equal(calls[0].init.method, 'POST')
	assert.deepEqual(calls[0].init.headers, { Accept: 'application/json', Authorization: 'Bearer tok' })
	assert.deepEqual([...calls[0].init.body.keys()], ['comment', 'files[]', 'files[]'])
	assert.equal(calls[0].init.body.get('files[]').name, 'bewijs.pdf')

	const bare = answering(() => response(422, { error: 'x' }))
	assert.deepEqual(await api().completeTask('t'), { ok: false, status: 422, code: '', body: { error: 'x' } })
	assert.deepEqual([...bare[0].init.body.keys()], [], 'no comment, no files')
	answering(() => response(500, new Error('not json')))
	assert.deepEqual(await api().completeTask('t', { comment: '' }), { ok: false, status: 500, code: '', body: {} })
	answering(() => new Error('offline'))
	assert.deepEqual(await api().completeTask('t'), { ok: false, status: 0, code: 'task-service-unreachable', body: {} })
	const anonymous = answering(() => response(200, {}))
	await createPortalApi({ apiBase: BASE }, { getToken: () => '', setToken() {} }).completeTask('t')
	assert.deepEqual(anonymous[0].init.headers, { Accept: 'application/json' })
})

test('a row action with files sends its answers as form fields, objects as key[name]', () => {
	const file = new File(['x'], 'foto.jpg')
	const form = rowActionFormData(
		{ reden: 'Verhuisd', aantal: 0, akkoord: false, leeg: '', niets: null, weg: undefined, adres: { straat: 'Dorpsweg', nummer: 3 }, lijst: ['a', 'b'] },
		{ field: 'bijlagen', files: [file] },
	)
	assert.deepEqual([...form.entries()].map(([k, v]) => [k, typeof v === 'string' ? v : v.name]), [
		['reden', 'Verhuisd'],
		['aantal', '0'],
		['akkoord', 'false'],
		['leeg', ''],
		['adres[straat]', 'Dorpsweg'],
		['adres[nummer]', '3'],
		['lijst[0]', 'a'],
		['lijst[1]', 'b'],
		['bijlagen[]', 'foto.jpg'],
	])
	assert.equal(rowActionFormData({ a: 1 }, { field: 'f', files: [] }), null)
	assert.equal(rowActionFormData({ a: 1 }, null), null)
})

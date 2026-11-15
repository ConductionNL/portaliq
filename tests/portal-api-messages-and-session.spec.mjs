#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// portal-api-messages-and-session.spec.mjs: which inbox messages the shared
// portal API (src/shared/portalApi.js) marks read, deletes or answers and
// which it refuses before asking, and when a branch choice or a dev login
// keeps a new bearer; pinned with allowed and refused outcomes before these
// were split into named steps.
//
// Usage:
//   node --test tests/portal-api-messages-and-session.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createPortalApi } from '../src/shared/portalApi.js'

const BASE = '/apps/portaliq/portal/api'

/**
 * A fetch that answers every call from `respond`, and the calls it saw.
 *
 * @param {(url: string) => object|Error} respond The answer, or an Error to throw.
 * @return {Array<object>} The calls, with the method, headers and parsed body.
 */
function answering(respond) {
	const calls = []
	globalThis.fetch = async (url, init = {}) => {
		calls.push({ url, method: init.method, headers: init.headers, body: init.body === undefined ? undefined : JSON.parse(init.body) })
		const answer = respond(url)
		if (answer instanceof Error) {
			throw answer
		}
		return answer
	}
	return calls
}

function response (status, body) {
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

/**
 * The api with a bearer store.
 *
 * @param {string} token The bearer it starts with.
 * @param {object} config Config over the api base.
 * @return {{api: object, written: Array<string>}} The api and the bearers it kept.
 */
function withStore(token = 'tok', config = {}) {
	const written = []
	const api = createPortalApi({ apiBase: BASE, ...config }, { getToken: () => token, setToken: (t) => written.push(t) })
	return { api, written }
}

const message = { id: 'm/1', _source: { register: 'r 1', schema: 's', collection: 'inbox' } }

test('a message with its id and source is marked read, deleted or answered at its own address', async () => {
	const calls = answering(() => response(200, { object: { id: 'x' } }))
	const { api } = withStore()
	assert.deepEqual(await api.markMessageRead(message), { ok: true, status: 200, object: { id: 'x' } })
	assert.deepEqual(await api.deleteMessage(message), { ok: true, status: 200, object: { id: 'x' } })
	assert.deepEqual(await api.replyToMessage(message, { tekst: 'Hallo' }), { ok: true, status: 200, object: { id: 'x' } })
	assert.deepEqual(calls.map((c) => [c.method, c.url, c.body]), [
		['PATCH', BASE + '/inbox/r%201/s/m%2F1/read?collection=inbox', {}],
		['DELETE', BASE + '/inbox/r%201/s/m%2F1?collection=inbox', {}],
		['POST', BASE + '/inbox/r%201/s/m%2F1/reply?collection=inbox', { tekst: 'Hallo' }],
	])
	assert.equal(calls[0].headers.Authorization, 'Bearer tok')
	const envelope = { '@self': { id: 'e' }, _source: { register: 'r', schema: 's' } }
	await api.deleteMessage(envelope)
	assert.equal(calls.at(-1).url, BASE + '/inbox/r/s/e?collection=')
	const refused = answering(() => response(403, { error: 'not_yours' }))
	const answer = await api.deleteMessage(message)
	assert.equal(answer.ok, false)
	assert.equal(answer.status, 403)
	assert.equal(refused.length, 1)
})

test('a message without an id, register or schema is refused before anything is asked', async () => {
	const calls = answering(() => response(200, {}))
	const { api } = withStore()
	for (const bad of [
		{ _source: { register: 'r', schema: 's' } },
		{ id: '', _source: { register: 'r', schema: 's' } },
		{ '@self': {}, _source: { register: 'r', schema: 's' } },
		{ id: 'm', _source: { schema: 's' } },
		{ id: 'm', _source: { register: 'r' } },
		{ id: 'm' },
	]) {
		assert.deepEqual(await api.markMessageRead(bad), { ok: false, status: 0 })
		assert.deepEqual(await api.deleteMessage(bad), { ok: false, status: 0 })
		assert.deepEqual(await api.replyToMessage(bad, { tekst: 'x' }), { ok: false, status: 0, object: null, error: '', errors: {} })
	}
	assert.deepEqual(calls, [])
})

test('a branch choice keeps the new bearer only when the server issues one', async () => {
	const calls = answering(() => response(200, { token: 'new' }))
	const { api, written } = withStore()
	assert.deepEqual(await api.chooseBranch('000012345678'), { ok: true })
	assert.deepEqual(await api.chooseBranch(''), { ok: true })
	assert.deepEqual(await api.chooseBranch(null), { ok: true })
	assert.deepEqual(written, ['new', 'new', 'new'])
	assert.deepEqual(calls.map((c) => [c.method, c.url, c.body]), [
		['POST', BASE + '/session/branch', { branch: '000012345678' }],
		['POST', BASE + '/session/branch', { branch: '' }],
		['POST', BASE + '/session/branch', { branch: '' }],
	])
	assert.deepEqual(calls[0].headers, { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: 'Bearer tok' })
	for (const answer of [response(403, { token: 'x' }), response(200, {}), response(200, { token: '' }), response(200, null), response(200, new Error('not json'))]) {
		answering(() => answer)
		const kept = withStore()
		assert.deepEqual(await kept.api.chooseBranch('1'), { ok: false })
		assert.deepEqual(kept.written, [], 'a refusal keeps the old bearer')
	}
	answering(() => response(200, { token: 'n' }))
	const anonymous = answering(() => response(200, { token: 'n' }))
	await withStore('').api.chooseBranch('1')
	assert.deepEqual(anonymous[0].headers, { 'Content-Type': 'application/json', Accept: 'application/json' })
})

test('a dev login keeps and answers the minted bearer, or null', async () => {
	const calls = answering(() => response(200, { token: 'dev' }))
	const { api, written } = withStore('tok', { audience: 'resident' })
	assert.equal(await api.devLogin('staff'), 'dev')
	assert.equal(await api.devLogin(''), 'dev')
	assert.equal(await withStore('tok').api.devLogin(), 'dev')
	assert.deepEqual(written, ['dev', 'dev'])
	assert.deepEqual(calls.map((c) => c.body.audience), ['staff', 'resident', 'supplier'])
	assert.deepEqual(calls[0].headers, { 'Content-Type': 'application/json', Accept: 'application/json' }, 'no bearer goes along')
	assert.deepEqual([calls[0].method, calls[0].url], ['POST', BASE + '/session/dev-login'])
	for (const answer of [response(404, { token: 'x' }), response(200, {}), response(200, null), response(200, new Error('x'))]) {
		answering(() => answer)
		const none = withStore()
		assert.equal(await none.api.devLogin('x'), null)
		assert.deepEqual(none.written, [])
	}
})

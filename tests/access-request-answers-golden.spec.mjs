#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// access-request-answers-golden.spec.mjs: what answering an access request
// sends and says beyond the main path: a row without an id, a row without
// an organisation, a blank reason and every failure status, pinned before
// the answer was split into named steps.
//
// Usage:
//   node --test tests/access-request-answers-golden.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createAccessRequestHandlers } from '../src/lib/accessRequestActions.js'

/**
 * The handlers over fake collaborators.
 *
 * @param {object} overrides Collaborators to replace.
 * @return {{handlers: object, calls: object}} The handlers and what they did.
 */
function build(overrides = {}) {
	const calls = { posts: [], notices: [], errors: [], reloads: 0 }
	const handlers = createAccessRequestHandlers({
		post: async (url, body) => {
			calls.posts.push({ url, body })
			return {}
		},
		generateUrl: (path, params) => path.replace('{id}', params.id).replace('{verb}', params.verb),
		confirmGrant: async () => true,
		askReason: async () => '  Geen machtiging  ',
		notify: (text) => calls.notices.push(text),
		notifyError: (text) => calls.errors.push(text),
		translate: (text) => `t:${text}`,
		reload: () => {
			calls.reloads++
		},
		...overrides,
	})
	return { handlers, calls }
}

test('a row without an id posts nothing and says it failed', async () => {
	const { handlers, calls } = build()
	assert.equal(await handlers.grantAccessRequest({ item: { organisation: 'o' } }), false)
	assert.equal(await handlers.refuseAccessRequest({ item: null }), false)
	assert.deepEqual(calls.posts, [])
	assert.equal(calls.errors.length, 2)
	assert.equal(calls.reloads, 0)
})

test('a row without an organisation sends an empty one; the reason is trimmed', async () => {
	const { handlers, calls } = build()
	assert.equal(await handlers.grantAccessRequest({ item: { id: 'r1' } }), true)
	assert.equal(await handlers.refuseAccessRequest({ item: { uuid: 'r2', organisation: 'o' } }), true)
	assert.deepEqual(calls.posts.map((post) => post.body), [{ organisation: '' }, { reason: 'Geen machtiging', organisation: 'o' }])
	assert.deepEqual(calls.notices, ['t:Access granted.', 't:Request refused.'])
	assert.equal(calls.reloads, 2)
})

test('a blank or missing reason refuses nothing; every failure says its sentence', async () => {
	for (const reason of ['   ', null, 5]) {
		const { handlers, calls } = build({ askReason: async () => reason })
		assert.equal(await handlers.refuseAccessRequest({ item: { id: 'r' } }), false)
		assert.deepEqual(calls.posts, [])
	}
	const sentences = []
	for (const status of [0, 400, 403, 404, 409, 500, undefined]) {
		const { handlers, calls } = build({
			post: async () => {
				throw status === undefined ? new Error('down') : { response: { status } }
			},
		})
		assert.equal(await handlers.grantAccessRequest({ item: { id: 'r' } }), false)
		assert.equal(calls.reloads, 0)
		sentences.push(calls.errors[0])
	}
	assert.equal(sentences.length, 7)
	assert.ok(sentences.every((sentence) => sentence.startsWith('t:')))
})

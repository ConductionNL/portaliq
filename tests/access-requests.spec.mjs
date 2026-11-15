#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// access-requests.spec.mjs: the Grant and Refuse row actions on the Access
// requests page (#797) post to the owner's routes, ask before a grant, never
// refuse without a reason, and say why an answer failed.
//
// Usage:
//   node --test tests/access-requests.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createAccessRequestHandlers } from '../src/lib/accessRequestActions.js'

const ROW = { uuid: 'req-1', organisation: 'gemeente-x', subjectRef: 'bookkeeper-1' }

/**
 * Handlers over recording collaborators.
 *
 * @param {object} overrides Collaborators to replace.
 * @return {{handlers: object, calls: object}}
 */
function build(overrides = {}) {
	const calls = { posts: [], notices: [], errors: [], reloads: 0 }
	const handlers = createAccessRequestHandlers({
		post: async (url, body) => {
			calls.posts.push({ url, body })
			return {}
		},
		generateUrl: (path, params) =>
			path.replace('{id}', params.id).replace('{verb}', params.verb),
		confirmGrant: async () => true,
		askReason: async () => 'No authorisation from the company',
		notify: (text) => calls.notices.push(text),
		notifyError: (text) => calls.errors.push(text),
		translate: (text) => text,
		reload: () => {
			calls.reloads++
		},
		...overrides,
	})
	return { handlers, calls }
}

test('a confirmed grant posts to the grant route with the row organisation', async () => {
	const { handlers, calls } = build()
	assert.equal(await handlers.grantAccessRequest({ item: ROW }), true)
	assert.deepEqual(calls.posts, [
		{
			url: '/apps/portaliq/api/access-requests/req-1/grant',
			body: { organisation: 'gemeente-x' },
		},
	])
	assert.equal(calls.reloads, 1)
})

test('a grant that is not confirmed posts nothing', async () => {
	const { handlers, calls } = build({ confirmGrant: async () => false })
	assert.equal(await handlers.grantAccessRequest({ item: ROW }), false)
	assert.deepEqual(calls.posts, [])
})

test('a refusal carries its reason, and a cancelled or empty one posts nothing', async () => {
	const { handlers, calls } = build()
	assert.equal(await handlers.refuseAccessRequest({ item: ROW }), true)
	assert.deepEqual(calls.posts, [
		{
			url: '/apps/portaliq/api/access-requests/req-1/refuse',
			body: {
				reason: 'No authorisation from the company',
				organisation: 'gemeente-x',
			},
		},
	])

	for (const reason of [null, '   ']) {
		const quiet = build({ askReason: async () => reason })
		assert.equal(await quiet.handlers.refuseAccessRequest({ item: ROW }), false)
		assert.deepEqual(quiet.calls.posts, [])
	}
})

test('a failed answer says why and does not reload', async () => {
	for (const [status, key] of [
		[
			403,
			'You may not answer access requests. Ask an administrator for this right.',
		],
		[409, 'Someone already answered this request.'],
		[
			502,
			'The access could not be recorded, so the request is still waiting. Try again.',
		],
	]) {
		const { handlers, calls } = build({
			post: async () => {
				throw { response: { status } }
			},
		})
		assert.equal(await handlers.grantAccessRequest({ item: ROW }), false)
		assert.deepEqual(calls.errors, [key])
		assert.equal(calls.reloads, 0)
	}
})

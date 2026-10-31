#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// submission-key.spec.mjs: one key per submission
// (action-forward-survives-a-slow-answer REQ-AFS-001, REQ-AFS-002).
//
// Usage:
//   node --test tests/submission-key.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createPortalApi } from '../src/shared/portalApi.js'
import {
	mayStillBeProcessing,
	newSubmissionKey,
	SUBMISSION_HEADER,
	submissionHeaders,
} from '../src/shared/submissionKey.js'
import { forwardApi } from '../src/site/lib/residentActions.js'

test('a new key is a token portaliq accepts, also without randomUUID', () => {
	assert.match(newSubmissionKey(), /^[A-Za-z0-9_-]{16,128}$/)
	assert.match(newSubmissionKey({}), /^[0-9a-f]{32}$/)
	assert.notEqual(newSubmissionKey(), newSubmissionKey())
})

test('only a usable key becomes a header', () => {
	const key = '0f8fad5b-d9cb-469f-a165-70867728950e'
	assert.deepEqual(submissionHeaders({ submissionKey: key }), {
		[SUBMISSION_HEADER]: key,
	})
	assert.deepEqual(submissionHeaders({ submissionKey: 'a b' }), {})
	assert.deepEqual(submissionHeaders(undefined), {})
})

test('a timeout or a busy key may still be processing; a refusal is not', () => {
	assert.equal(mayStillBeProcessing({ status: 504 }), true)
	assert.equal(
		mayStillBeProcessing({
			status: 409,
			object: { error: 'submission_processing' },
		}),
		true,
	)
	assert.equal(mayStillBeProcessing({ status: 502, body: {} }), false)
	assert.equal(mayStillBeProcessing({ status: 400, body: {} }), false)
})

test('both forward paths send the key in the header', async () => {
	const key = '0f8fad5b-d9cb-469f-a165-70867728950e'
	const seen = []
	const realFetch = globalThis.fetch
	globalThis.fetch = async (url, init) => {
		seen.push(init.headers[SUBMISSION_HEADER])
		return { ok: true, status: 201, json: async () => ({}) }
	}
	try {
		const api = createPortalApi(
			{ apiBase: '/portal/api' },
			{ getToken: () => '' },
		)
		await api.forwardAction('dossiq', 'start', {}, { submissionKey: key })
		await api.forwardRowAction(
			{ id: 'c', register: 'r', schema: 's' },
			'row-1',
			'start',
			{},
			'',
			null,
			{ submissionKey: key },
		)
	} finally {
		globalThis.fetch = realFetch
	}
	await forwardApi(
		'/portal/api',
		() => 'token',
		async (url, init) => {
			seen.push(init.headers[SUBMISSION_HEADER])
			return { ok: true, status: 201, json: async () => ({}) }
		},
	).forwardAction('dossiq', 'start', {}, { submissionKey: key })
	assert.deepEqual(seen, [key, key, key])
})

#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// portal-api.golden.spec.mjs: pins what the portal api's writes send and
// answer, and what the row and attached actions do with those answers
// (tests/Fixtures/Golden/portal-api.json).
//
// Usage:
//   node --test tests/golden/portal-api.golden.spec.mjs

import { test } from 'node:test'
import { runAttachedAction } from '../../src/shared/attachedActions.js'
import { createPortalApi } from '../../src/shared/portalApi.js'
import { runRowAction } from '../../src/shared/rowAction.js'
import { golden, outcomeOf, recordingFetch } from '../support/golden.mjs'

const ANSWERS = {
	ok: [{ status: 200, body: { object: { id: 'o1' }, case: { id: 'c1' }, withdrawal: { at: 'now' }, document: { name: 'd' }, file: { id: 'f1' }, value: 'v', id: 'draft-1', redirect: '/pay', link: '/shared/x', message: 'Gelukt' } }],
	created: [{ status: 201, body: { status: 'done' } }],
	plain: [{ status: 200, body: {} }],
	noJson: [{ status: 200 }],
	refused: [{ status: 400, body: { error: 'invalid', errors: { a: 'leeg' }, invalid: { b: 'type' }, message: 'Nee' } }],
	refusedNoJson: [{ status: 403 }],
	gone: [{ status: 404, body: { error: 'not_found' } }],
	bounceThenOk: [{ status: 503, body: {} }, { status: 200, body: { file: { id: 'f2' } } }],
	bounceTwice: [{ status: 502 }, { status: 504 }],
	networkThenOk: [{ throws: true }, { status: 200, body: {} }],
	network: [{ throws: true }],
}

const COLLECTION = { id: 'cases', register: 'dossiq', schema: 'case' }
const file = (name) => new File(['x'], name)

const API_CALLS = {
	replyToMessage: (api) => api.replyToMessage({ id: 'm1', _source: { register: 'r', schema: 's', collection: 'inbox' } }, { text: 'hoi' }),
	replyToMessageNoSource: (api) => api.replyToMessage({ id: 'm1' }, {}),
	setMessageLanguage: (api) => api.setMessageLanguage('en'),
	setDisplayName: (api) => api.setDisplayName('Vera'),
	forwardRowAction: (api) => api.forwardRowAction(COLLECTION, 'row 1', 'sign', { consent: true }),
	forwardRowActionBare: (api) => api.forwardRowAction(COLLECTION, 'row-1', 'pay'),
	forwardRowActionNullAnswers: (api) => api.forwardRowAction(COLLECTION, 'row-1', 'pay', null),
	forwardRowActionAttached: (api) => api.forwardRowAction(COLLECTION, 'row-1', 'ask', { q: 'w' }, 'pipelinq', null, { submissionKey: '0f8fad5b-d9cb-469f-a165' }),
	forwardRowActionUpload: (api) => api.forwardRowAction(COLLECTION, 'row-1', 'add', { note: 'n', when: { from: 1, to: 2 }, skip: null }, '', { field: 'docs', files: [file('a.pdf'), file('b.pdf')] }, { submissionKey: 'short' }),
	forwardRowActionEmptyUpload: (api) => api.forwardRowAction(COLLECTION, 'row-1', 'add', {}, '', { field: 'docs', files: [] }),
	forwardAction: (api) => api.forwardAction('dossiq', 'start', { a: 1 }, { submissionKey: '0f8fad5b-d9cb-469f-a165' }),
	amendCitizenCase: (api) => api.amendCitizenCase(COLLECTION, 'c 1', { phone: '06' }),
	withdrawCitizenCase: (api) => api.withdrawCitizenCase(COLLECTION, 'c1', 'Niet meer nodig'),
	addCitizenDocument: (api) => api.addCitizenDocument(COLLECTION, 'c1', file('brief.pdf')),
	saveDraft: (api) => api.saveDraft('dossiq', 'start/1', { answers: { a: 1 }, step: 's2' }),
	uploadFile: (api) => api.uploadFile(COLLECTION, 'o 1', file('foto.jpg')),
}

const ROW = { id: 'row-1' }
const ROW_ACTIONS = {
	runRowAction: (api) => runRowAction(api, { collection: COLLECTION, row: ROW, action: { id: 'pay' } }),
	runRowActionAnswers: (api) => runRowAction(api, { collection: COLLECTION, row: { '@self': { id: 'row-2' } }, action: { id: 'decline' }, answers: { reason: 'nee' } }),
	runRowActionFiles: (api) => runRowAction(api, { collection: COLLECTION, row: ROW, action: { id: 'add', files: { field: 'docs' } }, answers: { note: 'n' }, files: [file('a.pdf')] }),
	runRowActionNoFiles: (api) => runRowAction(api, { collection: COLLECTION, row: ROW, action: { id: 'add', files: { field: 'docs' } }, answers: { note: 'n' }, files: [] }),
	runRowActionNoRow: (api) => runRowAction(api, { collection: COLLECTION, row: {}, action: { id: 'pay' } }),
	runRowActionNoApi: () => runRowAction(null, { collection: COLLECTION, row: ROW, action: { id: 'pay' } }),
	runAttachedAction: (api) => runAttachedAction(api, { collection: COLLECTION, row: ROW, action: { id: 'ask', app: 'pipelinq', fields: ['question', 'title'], fieldConfigs: {} }, values: { question: 'Wanneer?' } }),
	runAttachedActionOptions: (api) => runAttachedAction(api, { collection: COLLECTION, row: ROW, action: { id: 'ask', app: 'pipelinq', fields: ['question'] }, values: { question: 'Nu' }, options: { submissionKey: '0f8fad5b-d9cb-469f-a165' } }),
	runAttachedActionNoRow: (api) => runAttachedAction(api, { collection: COLLECTION, row: null, action: { id: 'ask' }, values: {} }),
}

/**
 * A portal api double that records each forward and answers from a list.
 *
 * @param {object} answer The answer of every forward.
 * @return {{api: object, calls: Array}} The double and its calls.
 */
function forwardingApi(answer) {
	const calls = []
	return {
		calls,
		api: {
			async forwardRowAction(...args) {
				calls.push(args.map((arg) => (arg && typeof arg === 'object' && Array.isArray(arg.files) ? { ...arg, files: arg.files.map((f) => f.name) } : arg)))
				return answer
			},
		},
	}
}

test('the portal api writes send and answer as pinned', async () => {
	const pinned = golden('portal-api')
	const realFetch = globalThis.fetch
	try {
		for (const [name, call] of Object.entries(API_CALLS)) {
			for (const [answerName, answers] of Object.entries(ANSWERS)) {
				const recorder = recordingFetch(answers)
				globalThis.fetch = recorder.fetch
				const api = createPortalApi({ apiBase: '/portal/api' }, { getToken: () => 'tok' })
				const outcome = await outcomeOf(() => call(api))
				pinned.check(`${name}:${answerName}`, { calls: recorder.calls, outcome })
			}
		}
	} finally {
		globalThis.fetch = realFetch
	}
	const results = [
		{ ok: true, status: 200, body: { redirect: 'https://pay.example.nl/x' } },
		{ ok: true, status: 200, body: { link: '/shared/abc', message: 'Gedeeld' } },
		{ ok: false, status: 400, body: { errors: { reason: 'leeg' }, message: 'Nee' } },
		{ ok: false, status: 404, body: {} },
		{ ok: false, status: 0, body: {} },
		{ ok: true, status: 200 },
	]
	for (const [name, call] of Object.entries(ROW_ACTIONS)) {
		for (const [index, result] of results.entries()) {
			const double = forwardingApi(result)
			const outcome = await outcomeOf(() => call(double.api))
			pinned.check(`${name}:${index}`, { calls: double.calls, outcome })
		}
	}
	pinned.save()
})
